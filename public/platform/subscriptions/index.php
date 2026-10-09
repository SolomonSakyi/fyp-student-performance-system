<?php

/**
 * Subscription Management - Super Admin manages subscription plans
 *
 * @package EduTrack
 * @subpackage Platform\Subscriptions
 * @version 2.2
 * @filepath public/platform/subscriptions/index.php
 *
 * v2.2 change (2026-10-09) [QUERY-FIX + MOBILE-ASSETS]:
 *   Two changes.
 *
 *   1. QUERY-FIX: the page carried four malformed second comparisons
 *      on the deleted_at column, all of the form:
 *          (deleted_at IS NULL OR deleted_at = '')
 *      deleted_at is a datetime column. Comparing it to an empty
 *      string is a type-mismatched comparison that MariaDB 10.4
 *      (local dev) tolerates but that MySQL 9.7 (Railway production)
 *      can reject, producing an uncaught PDOException and a 500. The
 *      soft-delete predicate is deleted_at IS NULL alone. The OR
 *      branch is removed at all four sites:
 *        1. $plans query.
 *        2. $totalSubscriptions query.
 *        3. $totalTenants query.
 *        4. $activePlans query.
 *      This is the same class of fix applied to dashboard.php v1.1,
 *      schools/index.php v1.1, upgrade.php v2.1, and platform/index.php
 *      v2.3.
 *
 *   2. MOBILE-ASSETS: the four CDN references in <head> and before
 *      </body> were replaced by /assets/vendor/ paths, matching the
 *      change applied to dashboard.php v1.2, upgrade.php v2.1, and
 *      platform/index.php v2.3. The webfont files are already on the
 *      container under public/assets/vendor/webfonts/ from commit
 *      34486ae.
 *
 *   Every other line, query, variable, markup block, style rule, and
 *   script is byte-identical to v2.1.
 *
 * v2.1 change (2026-10-08) [SWEEP X-1 + SIDEBAR + REPAIR]:
 *   Platform-subscriptions sweep, X-1 in full, plus sidebar
 *   reconciliation, plus one repair.
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
 *   $currentPage stays 'subscriptions' so the Subscriptions item in
 *   the canonical partial is marked active. $pendingApprovals = 0 is
 *   set before the partial. $userFirstName and $userAvatar are
 *   already set above.
 *
 *   The page's .sidebar, .sidebar-toggle, .sidebar-header, .nav,
 *   .nav-label, .nav-link, .sidebar-footer, .user-info, .user-avatar,
 *   .user-name, .user-role, and .logout-btn CSS rules stay in the
 *   page's <style> block, because the partial ships markup only and
 *   no CSS.
 *
 *   The inline toggleSidebar() function, the inline click-outside
 *   handler, and the inline resize handler are removed from the
 *   page's bottom <script> block, because the partial provides them.
 *   The page's logout() and loadUserInfo() are kept — they are
 *   page-scoped and do not conflict with the partial.
 *
 *   Repair:
 *
 *     - LOGOUT. The inline logout() function previously redirected
 *       to '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php'
 *       — the login page, not the logout handler. Clicking Logout
 *       on this page did not clear the session. The redirect is
 *       changed to the relative '/platform/logout.php', matching
 *       the convention used by every other swept platform-root
 *       page.
 *
 *   Repairs that did NOT apply to this file (stated for the record):
 *
 *     - The is_super_admin guard is already present at lines 20-23.
 *       No change.
 *     - $projectRoot = dirname(__DIR__, 3) is already declared at the
 *       correct three-level depth for this file's path. No change.
 *     - config/config.php is already required inside a
 *       file_exists() guard. No change.
 *
 *   Open items on the record (NOT changed by this sweep):
 *
 *     - No CSRF surface on this page. The per-row Toggle and Delete
 *       actions are plain <a> GET links with a JS confirm() — not
 *       POST forms. The handlers they call (toggle.php, delete.php)
 *       each carry their own record. Any file in the subscriptions/
 *       folder that carries a state-changing form will need its own
 *       CSRF gate.
 *
 *     - The per-row Toggle link uses ?id={id}&status=... in a GET
 *       query string, and the Delete link uses ?id={id}. Both are
 *       non-POST state-changing actions. Stated but not changed.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK - Super Admin Only
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Subscription Management - Student 360 Platform';
$currentPage = 'subscriptions';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 3);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// GET ALL PLANS
// =============================================
$plans = $db->fetchAll(
    "SELECT * FROM subscription_plans
     WHERE deleted_at IS NULL
     ORDER BY price ASC"
);

// =============================================
// GET STATS
// =============================================
$totalSubscriptions = $db->getValue(
    "SELECT COUNT(*) FROM tenant_subscriptions WHERE status IN ('active', 'trial') AND deleted_at IS NULL"
);

$totalTenants = $db->getValue(
    "SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL"
);

$activePlans = $db->getValue(
    "SELECT COUNT(*) FROM subscription_plans WHERE is_active = 1 AND deleted_at IS NULL"
);

$totalPlans = count($plans);

// =============================================
// HANDLE MESSAGES
// =============================================
$message = '';
$messageType = '';

if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $messageType = $_GET['type'] ?? 'info';
}

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
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
    <?php /* [MOBILE-ASSETS] Stylesheets served from the app's own origin. */ ?>
    <link href="/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/vendor/fontawesome/all.min.css" rel="stylesheet">
    <link href="/assets/vendor/inter/inter.css" rel="stylesheet">
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
            background: #218838;
            color: #fff;
        }

        .btn-danger {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-danger:hover {
            background: #c82333;
            color: #fff;
        }

        .btn-warning {
            background: #ffc107;
            border: none;
            color: #1a1a2e;
        }

        .btn-warning:hover {
            background: #e0a800;
            color: #1a1a2e;
        }

        .btn-sm {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 8px;
        }

        /* ================================================ */
        /* CARD */
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
        /* STATS ROW */
        /* ================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
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

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .stat-card .stat-icon.orange {
            background: #ffe8d9;
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

        /* ================================================ */
        /* PLAN TABLE */
        /* ================================================ */
        .plan-table td,
        .plan-table th {
            vertical-align: middle;
            padding: 12px 16px;
        }

        .plan-table .badge-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .plan-table .badge-status.active {
            background: #d4edda;
            color: #155724;
        }

        .plan-table .badge-status.inactive {
            background: #e9ecef;
            color: #6c757d;
        }

        .plan-table .badge-status .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .plan-table .badge-status.active .dot {
            background: #28a745;
        }

        .plan-table .badge-status.inactive .dot {
            background: #6c757d;
        }

        /* ================================================ */
        /* ALERT */
        /* ================================================ */
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all 0.3s ease;
            animation: slideDown 0.4s ease;
            margin-bottom: 20px;
        }

        .alert-custom .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            width: 28px;
            text-align: center;
        }

        .alert-custom .alert-content {
            flex: 1;
        }

        .alert-custom .alert-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .alert-custom .alert-message {
            font-size: 13px;
            opacity: 0.9;
        }

        .alert-custom .btn-close-custom {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 18px;
            transition: opacity 0.2s;
            flex-shrink: 0;
            margin-top: -2px;
        }

        .alert-custom .btn-close-custom:hover {
            opacity: 1;
        }

        .alert-custom.alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #28a745;
        }

        .alert-custom.alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #dc3545;
        }

        .alert-custom.alert-warning {
            background: #fffbeb;
            color: #92400e;
            border-left: 4px solid #ffc107;
        }

        .alert-custom.alert-info {
            background: #eff6ff;
            color: #1e40af;
            border-left: 4px solid #4facfe;
        }

        @keyframes slideDown {
            0% {
                opacity: 0;
                transform: translateY(-10px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ================================================ */
        /* RESPONSIVE */
        /* ================================================ */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
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
                grid-template-columns: 1fr 1fr;
            }

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
                padding: 14px 16px;
            }

            .plan-table td,
            .plan-table th {
                padding: 8px 10px;
                font-size: 12px;
            }

            .plan-table .badge-status {
                font-size: 10px;
                padding: 2px 10px;
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
                grid-template-columns: 1fr;
            }

            .card-custom .card-body-custom {
                padding: 10px 12px;
            }

            .plan-table td,
            .plan-table th {
                padding: 6px 8px;
                font-size: 11px;
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
                        <h1><i class="fas fa-crown me-2"></i>Subscription Management</h1>
                        <p>Manage subscription plans and pricing for all tenants</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/subscriptions/create.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Create Plan
                        </a>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <?php if (!empty($message)): ?>
                    <div class="alert-custom alert-<?php echo $messageType; ?>">
                        <div class="alert-icon"><i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'danger' ? 'exclamation-circle' : ($messageType === 'warning' ? 'exclamation-triangle' : 'info-circle')); ?>"></i></div>
                        <div class="alert-content">
                            <div class="alert-title"><?php echo ucfirst($messageType); ?>!</div>
                            <div class="alert-message"><?php echo htmlspecialchars($message); ?></div>
                        </div>
                        <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-crown"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $activePlans; ?></div>
                            <div class="stat-label">Active Plans</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $totalSubscriptions; ?></div>
                            <div class="stat-label">Active Subscriptions</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $totalTenants; ?></div>
                            <div class="stat-label">Total Tenants</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-list"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $totalPlans; ?></div>
                            <div class="stat-label">Total Plans</div>
                        </div>
                    </div>
                </div>

                <!-- Plans Table -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-list me-2 text-primary"></i>All Subscription Plans</h6>
                        <span class="text-muted small"><?php echo $totalPlans; ?> plans available</span>
                    </div>
                    <div class="card-body-custom p-0">
                        <div class="table-responsive">
                            <table class="table plan-table mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Plan Name</th>
                                        <th>Code</th>
                                        <th>Price</th>
                                        <th>Schools</th>
                                        <th>Staff</th>
                                        <th>Students</th>
                                        <th>Storage</th>
                                        <th>Status</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($plans)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4 text-muted">
                                                <i class="fas fa-info-circle me-2"></i> No subscription plans found.
                                                <a href="/platform/subscriptions/create.php" class="text-primary">Create your first plan</a>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($plans as $plan): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($plan['plan_name']); ?></strong>
                                                    <?php if (isset($plan['is_popular']) && $plan['is_popular'] == 1): ?>
                                                        <span class="badge bg-warning text-dark ms-1">Popular</span>
                                                    <?php endif; ?>
                                                    <?php if (isset($plan['price']) && $plan['price'] == 0): ?>
                                                        <span class="badge bg-success ms-1">Free</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><code><?php echo htmlspecialchars($plan['plan_code']); ?></code></td>
                                                <td>
                                                    <?php if (isset($plan['price']) && $plan['price'] == 0): ?>
                                                        <span class="fw-bold text-success">Free</span>
                                                    <?php else: ?>
                                                        <span class="fw-bold"><?php echo htmlspecialchars($plan['currency'] ?? 'GHS'); ?> <?php echo number_format($plan['price'] ?? 0, 2); ?></span>
                                                        <span class="text-muted small">/ <?php echo $plan['billing_cycle'] ?? 'monthly'; ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo (isset($plan['max_schools']) && $plan['max_schools'] > 0) ? (int)$plan['max_schools'] : 'âˆž'; ?></td>
                                                <td><?php echo (isset($plan['max_staff']) && $plan['max_staff'] > 0) ? (int)$plan['max_staff'] : 'âˆž'; ?></td>
                                                <td><?php echo (isset($plan['max_students']) && $plan['max_students'] > 0) ? (int)$plan['max_students'] : 'âˆž'; ?></td>
                                                <td><?php echo (isset($plan['max_storage_mb']) && $plan['max_storage_mb'] > 0) ? (int)$plan['max_storage_mb'] . ' MB' : 'âˆž'; ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = (isset($plan['is_active']) && $plan['is_active'] == 1) ? 'active' : 'inactive';
                                                    $statusLabel = (isset($plan['is_active']) && $plan['is_active'] == 1) ? 'Active' : 'Inactive';
                                                    ?>
                                                    <span class="badge-status <?php echo $statusClass; ?>">
                                                        <span class="dot"></span> <?php echo $statusLabel; ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="d-flex gap-1 justify-content-center flex-wrap">
                                                        <a href="/platform/subscriptions/view.php?id=<?php echo $plan['id']; ?>" class="btn btn-outline-info btn-sm" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="/platform/subscriptions/edit.php?id=<?php echo $plan['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="/platform/subscriptions/toggle.php?id=<?php echo $plan['id']; ?>&status=<?php echo (isset($plan['is_active']) && $plan['is_active'] == 1) ? 'inactive' : 'active'; ?>"
                                                            class="btn btn-outline-<?php echo (isset($plan['is_active']) && $plan['is_active'] == 1) ? 'warning' : 'success'; ?> btn-sm"
                                                            title="<?php echo (isset($plan['is_active']) && $plan['is_active'] == 1) ? 'Deactivate' : 'Activate'; ?>"
                                                            onclick="return confirm('Are you sure you want to <?php echo (isset($plan['is_active']) && $plan['is_active'] == 1) ? 'deactivate' : 'activate'; ?> this plan?')">
                                                            <i class="fas fa-<?php echo (isset($plan['is_active']) && $plan['is_active'] == 1) ? 'pause' : 'play'; ?>"></i>
                                                        </a>
                                                        <a href="/platform/subscriptions/delete.php?id=<?php echo $plan['id']; ?>"
                                                            class="btn btn-outline-danger btn-sm"
                                                            title="Delete"
                                                            onclick="return confirm('Are you sure you want to delete this plan? This action cannot be undone.')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Footer Note -->
                <div class="text-center mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Total plans: <?php echo $totalPlans; ?> |
                        <span class="text-success">Active: <?php echo $activePlans; ?></span> |
                        <span class="text-muted">Inactive: <?php echo $totalPlans - $activePlans; ?></span>
                    </small>
                </div>
            </main>
        </div>
    </div>

    <?php /* [MOBILE-ASSETS] JavaScript served from the app's own origin. */ ?>
    <script src="/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script>
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
        // LOAD USER INFO
        // ================================================
        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
        });
    </script>
</body>

</html>