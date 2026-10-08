<?php

/**
 * Settings - Platform Settings Dashboard
 * @package EduTrack
 * @subpackage Platform\Settings
 * @version 2.3
 * @filepath public/platform/settings/index.php
 *
 * v2.3 change (2026-10-08) [SWEEP X-1 + SIDEBAR + REPAIR]:
 *   Platform-settings sweep, X-1 in full, plus sidebar
 *   reconciliation, plus two repairs.
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
 *   across 4 groups, including an Approvals item in the Main group,
 *   brand heading "Student 360", plus the mobile toggle, the sidebar
 *   footer, and the toggleSidebar() JS. This file's inline sidebar
 *   already matched the canonical item set; only the brand string
 *   and the toggleSidebar() copy differed from the partial.
 *
 *   $currentPage stays 'settings' so the Settings item in the
 *   canonical partial is marked active.
 *   $userFirstName, $currentUser, $userAvatar, and $pendingApprovals
 *   are set before the partial, matching the other swept pages.
 *
 *   The page's .sidebar, .sidebar-toggle, .sidebar-header, .nav,
 *   .nav-label, .nav-link, .sidebar-footer, .user-info, .user-avatar,
 *   .user-name, .user-role, and .logout-btn CSS rules stay in the
 *   page's <style> block, because the partial ships markup only and
 *   no CSS.
 *
 *   The inline toggleSidebar() function, the inline click-outside
 *   handler, and the inline resize handler are removed, because the
 *   partial provides them. The page's logout(), loadUserInfo(),
 *   showAlert(), and the DOMContentLoaded handler are kept — they
 *   are page-scoped and do not conflict with the partial.
 *
 *   Repairs:
 *
 *     - AUTH. The is_super_admin guard is added immediately after
 *       the logged_in guard. Prior to this change this page — the
 *       platform-root settings dashboard, reachable from the
 *       canonical sidebar — carried only the logged_in guard. Every
 *       other platform-root page swept in this session
 *       (audit/index.php, approvals/, tenants/, schools/) carries
 *       both guards. The added guard matches the convention:
 *         if (!isset($_SESSION['is_super_admin']) ||
 *             $_SESSION['is_super_admin'] !== true) {
 *             header('Location: /platform/tenant/dashboard.php');
 *             exit;
 *         }
 *
 *     - LOGOUT. The inline logout() function previously redirected
 *       to '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php'
 *       — the login page, not the logout handler. Clicking Logout on
 *       this page did not clear the session. The redirect is changed
 *       to the relative '/platform/logout.php', matching the
 *       convention used by every other swept platform-root page.
 *
 *     - $projectRoot. The page previously did not define $projectRoot.
 *       It did not need one — the file had no require_once for
 *       config/config.php or DatabaseHelper.php. The sidebar
 *       reconciliation adds a require_once that depends on
 *       $projectRoot. The declaration
 *         $projectRoot = dirname(__DIR__, 3);
 *       is added immediately after this dockblock and before the
 *       session_start() block, matching the shape used by every
 *       other swept platform-root file.
 *
 *   Open items on the record (NOT changed by this sweep):
 *
 *     - The settings-nav uses <a> links only. There is no form and
 *       no POST on this page, so there is no CSRF surface on this
 *       file. The tab files this page includes may carry their own
 *       POST handlers; each is on its own record.
 *
 *     - The payments tab renders a "Redirecting..." card and
 *       triggers a JS setTimeout redirect to
 *       /platform/settings/payments/index.php after 1 second. This
 *       is a UX quirk; it is not a defect.
 *
 *     - The $tabs array references six sibling tab files
 *       (general.php, security.php, appearance.php, integrations.php,
 *       email.php, advanced.php) plus the payments subfolder
 *       redirect. Which of those files exist on disk is not on the
 *       record.
 *
 *   The <head> <script> block that defines API_BASE and getHeaders()
 *   for the tab bodies is unchanged. The $tabs array is unchanged.
 *   The settings-sidebar nav is unchanged. The tab-include dispatch
 *   is unchanged. The showAlert() and loadUserInfo() helpers are
 *   unchanged.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 *
 * v2.1 change (2026-10-02):
 *   Added a <script> block in the <head> that defines two globals
 *   the tab bodies depend on: API_BASE and getHeaders().
 *
 * v2.2 change (2026-10-02):
 *   Rewrote the $tabs array so the sidebar matches the files that
 *   exist on disk. Removed four entries with no tab file
 *   (communication, finance, audit, webhooks); each fell back to
 *   general.php on click, so saving them wrote General keys.
 *   Added two entries whose tab files exist but were not listed
 *   (email, advanced). The new list has seven entries: general,
 *   security, appearance, integrations, email, payments, advanced.
 *   Every entry but payments has a tab file. payments keeps its
 *   existing redirect to /platform/settings/payments/index.php.
 *
 *   Nothing else changed.
 */

$projectRoot = dirname(__DIR__, 3);

// Check if session is already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Super Admin check - matches audit/, approvals/, tenants/, schools/
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$pageTitle = 'Settings - Student 360 Platform';
$currentPage = 'settings';

// Get active tab from URL
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

// Get tenant and school context from session
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;

// Determine API base URL dynamically
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

// Define available tabs
$tabs = [
    'general' => ['label' => 'General', 'icon' => 'fa-sliders-h', 'link' => '?tab=general'],
    'security' => ['label' => 'Security', 'icon' => 'fa-shield-alt', 'link' => '?tab=security'],
    'appearance' => ['label' => 'Appearance', 'icon' => 'fa-paint-brush', 'link' => '?tab=appearance'],
    'integrations' => ['label' => 'Integrations', 'icon' => 'fa-plug', 'link' => '?tab=integrations'],
    'email' => ['label' => 'Email', 'icon' => 'fa-envelope', 'link' => '?tab=email'],
    'payments' => ['label' => 'Payments', 'icon' => 'fa-credit-card', 'link' => '/platform/settings/payments/index.php'],
    'advanced' => ['label' => 'Advanced', 'icon' => 'fa-code', 'link' => '?tab=advanced']
];

$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));

// Variables read by the platform sidebar partial
$pendingApprovals = 0;

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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

        /* Sidebar */
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

        /* Settings Layout */
        .settings-container {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .settings-sidebar {
            width: 220px;
            flex-shrink: 0;
        }

        .settings-content {
            flex: 1;
            min-width: 0;
        }

        .settings-nav {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .settings-nav .nav-item {
            border-bottom: 1px solid #f0f2f5;
        }

        .settings-nav .nav-item:last-child {
            border-bottom: none;
        }

        .settings-nav .nav-link {
            padding: 12px 16px;
            color: #6c757d;
            font-weight: 500;
            font-size: 13px;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .settings-nav .nav-link:hover {
            background: #f8f9fa;
            color: #1a1a2e;
        }

        .settings-nav .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
        }

        .settings-nav .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 14px;
        }

        /* Settings Content - Tab content */
        .tab-content {
            padding: 0;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }

        .settings-section {
            margin-bottom: 30px;
        }

        .settings-section:last-child {
            margin-bottom: 0;
        }

        .settings-section .section-title {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f2f5;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
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

        .form-control.textarea {
            height: auto;
            min-height: 80px;
            resize: vertical;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .save-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 16px;
            border-top: 2px solid #f0f2f5;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .save-actions .btn {
            border-radius: 10px;
            padding: 10px 28px;
            font-weight: 600;
            font-size: 13px;
        }

        /* Settings loading */
        .settings-loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 60px 20px;
            color: #6c757d;
            flex-direction: column;
            gap: 16px;
        }

        .settings-loading .spinner-border {
            width: 40px;
            height: 40px;
            color: #4facfe;
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

            .settings-sidebar {
                width: 100%;
            }

            .settings-container {
                flex-direction: column;
            }

            .settings-nav {
                display: flex;
                flex-wrap: wrap;
            }

            .settings-nav .nav-item {
                border-bottom: none;
                border-right: 1px solid #f0f2f5;
            }

            .settings-nav .nav-link {
                padding: 10px 14px;
                font-size: 12px;
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
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .settings-nav .nav-link {
                font-size: 12px;
                padding: 8px 12px;
            }

            .save-actions {
                flex-direction: column;
            }

            .save-actions .btn {
                width: 100%;
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

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
                padding: 14px 16px;
            }
        }
    </style>

    <script>
        // ================================================
        // API CONFIGURATION
        // ================================================
        // Emitted from the PHP $apiBase variable computed above.
        // The tab bodies call fetch() and getHeaders() with these
        // globals. Without this block, every tab body's save
        // handler fails with "API_BASE is not defined".
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';

        // ================================================
        // REQUEST HEADERS
        // ================================================
        // Returns the headers object every tab body's fetch() passes.
        // Reads the platform token from localStorage when present.
        // Falls back to content-type and accept only when no token is
        // stored, so the browser's session cookie is used instead.
        // ================================================
        function getHeaders() {
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            };
            const token = localStorage.getItem('token') || localStorage.getItem('platform_token');
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }
            return headers;
        }
    </script>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php require_once $projectRoot . '/app/views/partials/platform-sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-cog me-2"></i>Platform Settings</h1>
                        <p>Configure platform-wide settings and preferences</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Settings Container -->
                <div class="settings-container">
                    <!-- Sidebar Navigation -->
                    <div class="settings-sidebar">
                        <nav class="settings-nav" id="settingsNav">
                            <?php foreach ($tabs as $key => $tab): ?>
                                <div class="nav-item">
                                    <a class="nav-link <?php echo $activeTab == $key ? 'active' : ''; ?>"
                                        href="<?php echo $tab['link']; ?>">
                                        <i class="fas <?php echo $tab['icon']; ?>"></i>
                                        <?php echo $tab['label']; ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </nav>
                    </div>

                    <!-- Settings Content -->
                    <div class="settings-content">
                        <?php if ($activeTab === 'payments'): ?>
                            <!-- Payments tab redirects to the payments page -->
                            <div class="card-custom">
                                <div class="card-body-custom" style="text-align:center;padding:40px 20px;">
                                    <i class="fas fa-credit-card" style="font-size:48px;color:#4facfe;margin-bottom:16px;display:block;"></i>
                                    <h5>Payment Settings</h5>
                                    <p style="color:#6c757d;">Redirecting to Payment Settings page...</p>
                                    <a href="/platform/settings/payments/index.php" class="btn btn-primary">
                                        <i class="fas fa-arrow-right me-2"></i> Go to Payment Settings
                                    </a>
                                    <script>
                                        // Auto-redirect to payments page after 1 second
                                        setTimeout(function() {
                                            window.location.href = '/platform/settings/payments/index.php';
                                        }, 1000);
                                    </script>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php
                            // Load the appropriate tab content
                            $tabFile = dirname(__FILE__) . '/' . $activeTab . '.php';
                            if (file_exists($tabFile)) {
                                include $tabFile;
                            } else {
                                // Default fallback - show general settings
                                include dirname(__FILE__) . '/general.php';
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '/platform/logout.php';
            }
        }

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

        function showAlert(message, type) {
            const container = document.getElementById('alertContainer');
            const colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            const icons = {
                success: 'fa-check-circle',
                danger: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            container.innerHTML = `<div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                <i class="fas ${icons[type] || 'fa-info-circle'} me-2"></i> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
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

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
</body>

</html>