<?php

/**
 * Domains Management - List all domains
 * 
 * @package EduTrack
 * @subpackage Platform\Domains
 * @version 2.1
 * @filepath public/platform/domains/index.php
 *
 * v2.1 change (2026-10-08) [SWEEP X-1 + SIDEBAR + REPAIRS]:
 *   Platform-domains sweep, X-1 in full, plus sidebar
 *   reconciliation, plus three repairs.
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
 *   file's path is public/platform/domains/index.php, so three
 *   levels up resolves to the project root. The declaration is
 *   added in the same change as the partial require, because the
 *   file did not carry $projectRoot before this sweep.
 *
 *   $currentPage stays 'domains' so the Domains item in the
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
 *   page's bottom <script> block, because the partial provides them.
 *   Every tab-body and modal function in that <script> block is
 *   retained.
 *
 *   Repairs:
 *
 *     - SESSION_START. The file previously called session_start()
 *       unguarded. Every other swept platform-root file wraps it in
 *       if (session_status() === PHP_SESSION_NONE) { session_start(); }.
 *       The guard is added to prevent a PHP notice when a session is
 *       already active on the request.
 *
 *     - AUTH REDIRECT. The is_super_admin guard previously
 *       redirected to /platform/index.php. Every other swept
 *       platform-root file redirects to
 *       /platform/tenant/dashboard.php. The target is aligned to
 *       /platform/tenant/dashboard.php.
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
 *     - No CSRF surface on this page. The stats row, the filters
 *       bar, the domains table, and the viewDomain modal all use
 *       client-side fetch() calls to the
 *       {API_BASE}/index.php?endpoint=domains&action=... endpoints.
 *       There is no PHP POST form on this page. The API endpoint
 *       implementation is not on the record. Any file that
 *       implements those endpoints in PHP will carry its own CSRF
 *       requirement separately.
 *
 *     - The viewDomain() helper carries multiple fallback shapes
 *       for the response payload (result.data.id, result.data.domain.id,
 *       result.data.data.id, result.id). This is defensive parsing;
 *       it is not a defect.
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

// Check if user is Super Admin (role_id = 2)
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$pageTitle = 'Domain Management - Student 360 Platform';
$currentPage = 'domains';

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

// Project root (three levels up from public/platform/domains/index.php)
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

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 200px;
        }

        .filters-bar .filter-group label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            white-space: nowrap;
        }

        .filters-bar .filter-group .form-control,
        .filters-bar .filter-group .form-select {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
        }

        .filters-bar .filter-group .form-control {
            flex: 1;
        }

        .filters-bar .filter-group .form-select {
            min-width: 150px;
        }

        .search-wrapper {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

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

        .table-container {
            background: #fff;
            border-radius: 14px;
            padding: 0;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            width: 100%;
        }

        .table-container .table {
            margin: 0;
            font-size: 13px;
            width: 100%;
        }

        .table-container .table thead th {
            background: #f8f9fa;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .table-container .table tbody td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .table-container .table tbody tr:hover {
            background: #f8f9fa;
        }

        .table-container .table tbody tr:last-child td {
            border-bottom: none;
        }

        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.active {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.verified {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-status.archived {
            background: #e9ecef;
            color: #6c757d;
        }

        .table-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .table-actions .btn {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 6px;
            line-height: 1.4;
        }

        .table-actions .btn i {
            margin-right: 2px;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pagination-wrapper .info {
            color: #6c757d;
            font-size: 13px;
        }

        .pagination {
            margin: 0;
        }

        .pagination .page-link {
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
            color: #1a1a2e;
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-color: #4facfe;
            color: #fff;
        }

        .modal-content {
            border-radius: 16px;
            border: none;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            border-bottom: 1px solid #f0f2f5;
            padding: 16px 24px;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            border-top: 1px solid #f0f2f5;
            padding: 16px 24px;
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
            padding: 8px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            max-width: 100%;
            background: #fff;
            color: #1a1a2e;
            transition: all 0.3s;
            height: 40px;
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

        .alert {
            border-radius: 10px;
            border: none;
            font-size: 13px;
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

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
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

            .table-container .table {
                font-size: 12px;
            }

            .table-container .table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .table-container .table tbody td {
                padding: 8px 10px;
            }

            .table-actions .btn {
                font-size: 11px;
                padding: 2px 6px;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
                flex-wrap: wrap;
            }

            .filters-bar .filter-group label {
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
                        <h1><i class="fas fa-globe me-2"></i>Domain Management</h1>
                        <p>Manage custom domains for tenants</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/domains/create.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Domain
                        </a>
                    </div>
                </div>

                <div id="alertContainer"></div>

                <div class="filters-bar">
                    <div class="filter-group">
                        <label><i class="fas fa-search me-1"></i> Search</label>
                        <div class="search-wrapper">
                            <input type="text" class="form-control" id="searchInput" placeholder="Search domains..." oninput="handleSearchInput()" onkeydown="handleSearchKeydown(event)">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-filter me-1"></i> Status</label>
                        <select class="form-select" id="statusFilter" onchange="applyFilters()">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="verified">Verified</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button class="btn btn-outline-secondary" onclick="clearFilters()">
                            <i class="fas fa-times me-1"></i> Clear
                        </button>
                        <button class="btn btn-primary" onclick="applyFilters()">
                            <i class="fas fa-search me-1"></i> Apply
                        </button>
                    </div>
                </div>

                <div class="stats-row" id="statsRow">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-globe"></i></div>
                        <div>
                            <div class="stat-number" id="totalDomains">0</div>
                            <div class="stat-label">Total Domains</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number" id="activeDomains">0</div>
                            <div class="stat-label">Active Domains</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="stat-number" id="pendingDomains">0</div>
                            <div class="stat-label">Pending Domains</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="stat-number" id="tenantDomains">0</div>
                            <div class="stat-label">Tenants with Domains</div>
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Tenant</th>
                                <th>Status</th>
                                <th>Primary</th>
                                <th>SSL</th>
                                <th>Created</th>
                                <th style="text-align:center;min-width:200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="domainsTableBody">
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Loading domains...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="pagination-wrapper">
                        <div class="info" id="paginationInfo">Showing 0 of 0 domains</div>
                        <nav>
                            <ul class="pagination" id="paginationControls">
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadDomains(1)">First</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadDomains(currentPage - 1)">Prev</a></li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadDomains(currentPage + 1)">Next</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadDomains(totalPages)">Last</a></li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="viewDomainModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2 text-primary"></i>Domain Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewDomainContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';
        let currentPage = 1;
        let totalPages = 1;
        let totalRecords = 0;
        let searchTimeout = null;

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '/platform/logout.php';
            }
        }

        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json'
            };
        }

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        function getStatusBadge(status) {
            const map = {
                'active': '<span class="badge-status active">Active</span>',
                'inactive': '<span class="badge-status inactive">Inactive</span>',
                'pending': '<span class="badge-status pending">Pending</span>',
                'verified': '<span class="badge-status verified">Verified</span>',
                'archived': '<span class="badge-status archived">Archived</span>'
            };
            return map[status] || '<span class="badge-status">' + status + '</span>';
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
            container.innerHTML = `
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                    <i class="fas ${icons[type] || 'fa-info-circle'} me-2"></i>
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

        async function handleSearchInput() {
            const searchTerm = document.getElementById('searchInput').value.trim();
            clearTimeout(searchTimeout);
            if (searchTerm.length < 2) return;
            searchTimeout = setTimeout(() => {
                applyFilters();
            }, 300);
        }

        function handleSearchKeydown(event) {
            if (event.key === 'Enter') applyFilters();
        }

        function getFilters() {
            return {
                search: document.getElementById('searchInput').value.trim(),
                status: document.getElementById('statusFilter').value
            };
        }

        function applyFilters() {
            loadDomains(1);
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            loadDomains(1);
        }

        async function viewDomain(id) {
            const modal = new bootstrap.Modal(document.getElementById('viewDomainModal'));
            const content = document.getElementById('viewDomainContent');
            content.innerHTML =
                `<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>`;
            modal.show();

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=domains&action=get&id=${id}`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                if (result.success) {
                    let domain = null;
                    if (result.data && result.data.id) domain = result.data;
                    else if (result.data && result.data.domain && result.data.domain.id) domain = result.data.domain;
                    else if (result.data && result.data.data && result.data.data.id) domain = result.data.data;
                    else if (result.id) domain = result;

                    if (domain && domain.id) {
                        const statusBadge = getStatusBadge(domain.status);
                        const primaryBadge = domain.is_primary ? '<span class="badge bg-success">Yes</span>' :
                            '<span class="badge bg-secondary">No</span>';
                        const sslBadge = domain.ssl_enabled ? '<span class="badge bg-success">Enabled</span>' :
                            '<span class="badge bg-danger">Disabled</span>';
                        const tenantName = domain.tenant_name || 'Tenant #' + domain.tenant_id;

                        content.innerHTML = `
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3"><label class="text-muted small text-uppercase fw-bold">Domain Name</label><p class="fw-bold fs-5">${domain.domain_name}</p></div>
                                    <div class="mb-3"><label class="text-muted small text-uppercase fw-bold">Tenant</label><p>${tenantName}</p></div>
                                    <div class="mb-3"><label class="text-muted small text-uppercase fw-bold">Status</label><p>${statusBadge}</p></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3"><label class="text-muted small text-uppercase fw-bold">Primary Domain</label><p>${primaryBadge}</p></div>
                                    <div class="mb-3"><label class="text-muted small text-uppercase fw-bold">SSL Enabled</label><p>${sslBadge}</p></div>
                                    <div class="mb-3"><label class="text-muted small text-uppercase fw-bold">Created At</label><p>${formatDate(domain.created_at)}</p></div>
                                </div>
                            </div>
                            <div class="mt-3"><a href="/platform/domains/edit.php?id=${domain.id}" class="btn btn-primary btn-sm"><i class="fas fa-edit me-1"></i> Edit</a></div>
                        `;
                    } else {
                        content.innerHTML = `<div class="alert alert-warning">Domain data not found.</div>`;
                    }
                } else {
                    content.innerHTML = `<div class="alert alert-danger">Failed to load domain details: ${result.message}</div>`;
                }
            } catch (error) {
                content.innerHTML = `<div class="alert alert-danger">Error loading domain: ${error.message}</div>`;
            }
        }

        async function verifyDomain(id) {
            if (!confirm('Verify this domain?')) return;
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=domains&action=verify&id=${id}`, {
                    method: 'POST',
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Domain verified successfully', 'success');
                    loadDomains(currentPage);
                    loadStats();
                } else {
                    showAlert('✗ ' + (result.message || 'Failed to verify domain'), 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }

        async function activateDomain(id) {
            if (!confirm('Activate this domain?')) return;
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=domains&action=activate&id=${id}`, {
                    method: 'PUT',
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Domain activated successfully', 'success');
                    loadDomains(currentPage);
                    loadStats();
                } else {
                    showAlert('✗ ' + (result.message || 'Failed to activate domain'), 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }

        async function deactivateDomain(id) {
            if (!confirm('Deactivate this domain?')) return;
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=domains&action=deactivate&id=${id}`, {
                    method: 'PUT',
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Domain deactivated successfully', 'success');
                    loadDomains(currentPage);
                    loadStats();
                } else {
                    showAlert('✗ ' + (result.message || 'Failed to deactivate domain'), 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }

        async function setPrimaryDomain(id) {
            if (!confirm('Set this as the primary domain for the tenant?')) return;
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=domains&action=update&id=${id}`, {
                    method: 'PUT',
                    headers: getHeaders(),
                    body: JSON.stringify({
                        is_primary: 1
                    })
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Primary domain updated successfully', 'success');
                    loadDomains(currentPage);
                } else {
                    showAlert('✗ ' + (result.message || 'Failed to update primary domain'), 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }

        async function deleteDomain(id) {
            if (!confirm('Are you sure you want to delete this domain?')) return;
            if (!confirm('Really? This action cannot be undone.')) return;
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=domains&action=delete&id=${id}`, {
                    method: 'DELETE',
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Domain deleted successfully', 'success');
                    loadDomains(currentPage);
                    loadStats();
                } else {
                    showAlert('✗ ' + (result.message || 'Failed to delete domain'), 'danger');
                }
            } catch (error) {
                showAlert('Error: ' + error.message, 'danger');
            }
        }

        async function loadStats() {
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=domains&action=stats`, {
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    let stats = result.data || result;
                    document.getElementById('totalDomains').textContent = stats.total || 0;
                    document.getElementById('activeDomains').textContent = stats.active || 0;
                    document.getElementById('pendingDomains').textContent = stats.pending || 0;
                    document.getElementById('tenantDomains').textContent = stats.tenants || 0;
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }

        async function loadDomains(page) {
            currentPage = page || 1;
            const tbody = document.getElementById('domainsTableBody');
            const filters = getFilters();

            tbody.innerHTML =
                `<tr><td colspan="7" class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2 text-muted">Loading domains...</p></td></tr>`;

            try {
                let url =
                    `${API_BASE}/index.php?endpoint=domains&action=list&page=${currentPage}&limit=20`;
                if (filters.search) url += `&search=${encodeURIComponent(filters.search)}`;
                if (filters.status) url += `&status=${encodeURIComponent(filters.status)}`;

                const response = await fetch(url, {
                    headers: getHeaders()
                });
                const result = await response.json();

                if (result.success) {
                    let domains = [];
                    let total = 0;

                    if (result.data && result.data.domains) {
                        domains = result.data.domains || [];
                        total = result.data.total || domains.length;
                        totalPages = result.data.total_pages || 1;
                    } else if (result.data && Array.isArray(result.data)) {
                        domains = result.data;
                        total = domains.length;
                        totalPages = 1;
                    } else if (result.domains) {
                        domains = result.domains || [];
                        total = result.total || domains.length;
                        totalPages = result.total_pages || 1;
                    } else if (Array.isArray(result)) {
                        domains = result;
                        total = domains.length;
                        totalPages = 1;
                    } else {
                        domains = [];
                        total = 0;
                        totalPages = 1;
                    }

                    totalRecords = total;

                    if (domains.length === 0) {
                        tbody.innerHTML =
                            `<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-globe fa-2x d-block mb-2"></i>No domains found</td></tr>`;
                    } else {
                        tbody.innerHTML = domains.map((domain) => {
                            const statusBadge = getStatusBadge(domain.status);
                            const isPrimary = domain.is_primary ? '<span class="badge bg-primary">Primary</span>' :
                                '<span class="badge bg-secondary">No</span>';
                            const ssl = domain.ssl_enabled ? '<span class="badge bg-success">Enabled</span>' :
                                '<span class="badge bg-danger">Disabled</span>';
                            const tenantName = domain.tenant_name || 'Tenant #' + domain.tenant_id;

                            return `
                                <tr>
                                    <td><div style="font-weight:600;font-size:14px;">${domain.domain_name || 'N/A'}</div></td>
                                    <td>${tenantName}</td>
                                    <td>${statusBadge}</td>
                                    <td style="text-align:center;">${isPrimary}</td>
                                    <td style="text-align:center;">${ssl}</td>
                                    <td style="font-size:12px;color:#6c757d;">${formatDate(domain.created_at)}</td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="btn btn-sm btn-outline-info" onclick="viewDomain(${domain.id})" title="View"><i class="fas fa-eye"></i></button>
                                            <a href="/platform/domains/edit.php?id=${domain.id}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                            ${domain.status === 'pending' ? `<button class="btn btn-sm btn-outline-success" onclick="verifyDomain(${domain.id})" title="Verify"><i class="fas fa-check"></i></button>` : ''}
                                            ${domain.status === 'active' || domain.status === 'verified' ? `<button class="btn btn-sm btn-outline-warning" onclick="deactivateDomain(${domain.id})" title="Deactivate"><i class="fas fa-pause"></i></button>` : ''}
                                            ${domain.status === 'inactive' ? `<button class="btn btn-sm btn-outline-success" onclick="activateDomain(${domain.id})" title="Activate"><i class="fas fa-play"></i></button>` : ''}
                                            ${!domain.is_primary ? `<button class="btn btn-sm btn-outline-secondary" onclick="setPrimaryDomain(${domain.id})" title="Set Primary"><i class="fas fa-star"></i></button>` : ''}
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteDomain(${domain.id})" title="Delete"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('');
                    }
                    updatePagination();
                } else {
                    tbody.innerHTML =
                        `<tr><td colspan="7" class="text-center py-4 text-danger"><i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>${result.message || 'Failed to load domains'}</td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML =
                    `<tr><td colspan="7" class="text-center py-4 text-danger"><i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>Error loading domains: ${error.message}</td></tr>`;
            }
        }

        function updatePagination() {
            const info = document.getElementById('paginationInfo');
            const controls = document.getElementById('paginationControls');
            const current = currentPage;
            const total = totalPages;

            const start = (current - 1) * 20 + 1;
            const end = Math.min(current * 20, totalRecords);
            info.textContent = `Showing ${start} to ${end} of ${totalRecords} domains`;

            let html =
                `
                <li class="page-item ${current <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="loadDomains(1)">First</a></li>
                <li class="page-item ${current <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="loadDomains(${current - 1})">Prev</a></li>
            `;

            const maxPages = 7;
            let startPage = Math.max(1, current - Math.floor(maxPages / 2));
            let endPage = Math.min(total, startPage + maxPages - 1);
            if (endPage - startPage < maxPages - 1) {
                startPage = Math.max(1, endPage - maxPages + 1);
            }

            if (startPage > 1) {
                html += `<li class="page-item disabled"><a class="page-link">...</a></li>`;
            }

            for (let i = startPage; i <= endPage; i++) {
                html += `
                    <li class="page-item ${i === current ? 'active' : ''}"><a class="page-link" href="#" onclick="loadDomains(${i})">${i}</a></li>
                `;
            }

            if (endPage < total) {
                html += `<li class="page-item disabled"><a class="page-link">...</a></li>`;
            }

            html += `
                <li class="page-item ${current >= total ? 'disabled' : ''}"><a class="page-link" href="#" onclick="loadDomains(${current + 1})">Next</a></li>
                <li class="page-item ${current >= total ? 'disabled' : ''}"><a class="page-link" href="#" onclick="loadDomains(${total})">Last</a></li>
            `;

            controls.innerHTML = html;
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadStats();
            loadDomains(1);
        });
    </script>
</body>

</html>