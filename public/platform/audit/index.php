<?php

/**
 * Audit Logs - View and filter platform audit logs
 *
 * @package EduTrack
 * @subpackage Platform\Audit
 * @version 1.0
 * @filepath public/platform/audit/index.php
 *
 * v1.0 change (2026-10-07) [SWEEP X-1 + SIDEBAR]:
 *   Platform-audit sweep, X-1 in full, plus sidebar reconciliation.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 11 items across
 *   4 groups (Main, Institution, Management, System), brand heading
 *   "EduTrack", width 260px — is replaced by a single require_once
 *   of the shared partial at app/views/partials/platform-sidebar.php
 *   v1.0. That partial carries the canonical platform-root sidebar
 *   shape: 11 items across 4 groups (Main, Institution, Management,
 *   System), including an Approvals item in the Main group, brand
 *   heading "Student 360", folder paths for Tenants and Settings, no
 *   Register Tenant item, plus the mobile toggle, the sidebar footer,
 *   and the toggleSidebar() JS.
 *
 *   The canonical partial has no item that matches this page's
 *   $currentPage value beyond Audit Logs itself; $currentPage stays
 *   'audit' so the Audit Logs item is marked active on this page.
 *
 *   $projectRoot, $userName, $currentUser, $userAvatar, and
 *   $pendingApprovals = 0 are set explicitly before the partial is
 *   required, matching the partial's variable contract.
 *
 *   The page's .sidebar, .sidebar-toggle, .sidebar-header, .nav,
 *   .nav-label, .nav-link, .sidebar-footer, .user-info, .user-avatar,
 *   .user-name, .user-role, and .logout-btn CSS rules stay in the
 *   page's <style> block, because the partial ships markup only and
 *   no CSS.
 *
 *   The inline toggleSidebar() function that this page carried in its
 *   own <script> block is removed, because the partial provides it.
 *   The page's click-outside handler and resize handler are also
 *   removed for the same reason. The page's logout(), loadUserInfo(),
 *   showAlert(), formatNumber(), formatDate(), formatDateShort(),
 *   getActionBadge(), getModuleBadge(), loadTenantDropdown(),
 *   useMockTenants(), loadStats(), loadLogs(), updatePagination(),
 *   resetFilters(), viewLog(), exportLogs(), cleanLogs(), and the
 *   DOMContentLoaded handlers are kept — they are page-scoped and do
 *   not conflict with the partial.
 *
 *   No config.php is added by this sweep: the file does not use it.
 *   The page reads no table directly; it loads all data client-side
 *   via fetch() against the /api/platform endpoints.
 *
 *   v1.0 (2026-10-08) [API HOST]: The API_BASE constant is changed
 *   from 'http://localhost:8000/api/platform' to
 *   'http://admin.edutrack.local/api/platform', matching the host
 *   that serves this page. The prior value targeted a local dev
 *   server on port 8000 that is not running, producing
 *   net::ERR_CONNECTION_REFUSED on every fetch. The endpoints
 *   (endpoint=tenants, endpoint=audit-logs), the query strings, the
 *   headers, the error branches, and every other line are
 *   byte-identical to the swept file. The logout() redirect string
 *   is unchanged.
 *
 *   NOTE on record: nothing outside the platform-root sidebar links
 *   to this file other than the sidebar itself. audit/view.php is
 *   the within-folder inbound from this page's per-row View Details
 *   button (viewLog()). The audit-logs and tenants API endpoints
 *   this page calls are not on the record.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// ============================================================
// PAGE SETUP
// ============================================================
$pageTitle = 'Audit Logs - Student 360 Platform';
$currentPage = 'audit';

// Variables read by the platform sidebar partial
$projectRoot = dirname(__DIR__, 3);
$userName = $_SESSION['user_name'] ?? 'Admin';
$currentUser = $userName;
$userAvatar = strtoupper(substr($userName, 0, 1));
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

        .stat-card .stat-icon.red {
            background: #f8d7da;
            color: #dc3545;
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

        /* ================================================ */
        /* FILTER SECTION                                 */
        /* ================================================ */
        .filter-section {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filter-section .filter-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }

        .filter-section .filter-row .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-section .filter-row .filter-group label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin: 0;
            white-space: nowrap;
        }

        .filter-section .filter-row .filter-group .form-control,
        .filter-section .filter-row .filter-group .form-select {
            border-radius: 8px;
            padding: 6px 12px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            height: 38px;
            min-width: 130px;
            background: #fff;
        }

        .filter-section .filter-row .filter-group .form-control:focus,
        .filter-section .filter-row .filter-group .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .filter-section .filter-row .filter-group .btn {
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            height: 38px;
        }

        .filter-section .filter-row .filter-group .btn i {
            margin-right: 4px;
        }

        .filter-section .filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        /* ================================================ */
        /* TABLE - MATCHES DASHBOARD                      */
        /* ================================================ */
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

        /* ================================================ */
        /* BADGES                                         */
        /* ================================================ */
        .badge-action {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-action.create {
            background: #d4edda;
            color: #155724;
        }

        .badge-action.update {
            background: #cce5ff;
            color: #004085;
        }

        .badge-action.delete {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-action.login {
            background: #e3f0ff;
            color: #0d47a1;
        }

        .badge-action.logout {
            background: #fce4ec;
            color: #880e4f;
        }

        .badge-action.activate {
            background: #d4edda;
            color: #155724;
        }

        .badge-action.deactivate {
            background: #fff3cd;
            color: #856404;
        }

        .badge-action.assign {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .badge-action.default {
            background: #e9ecef;
            color: #6c757d;
        }

        .badge-module {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-module.tenant {
            background: #e3f0ff;
            color: #0d47a1;
        }

        .badge-module.user {
            background: #d4edda;
            color: #155724;
        }

        .badge-module.subscription {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .badge-module.domain {
            background: #fff3cd;
            color: #856404;
        }

        .badge-module.auth {
            background: #fce4ec;
            color: #880e4f;
        }

        .badge-module.system {
            background: #e9ecef;
            color: #6c757d;
        }

        .badge-module.default {
            background: #f8f9fa;
            color: #6c757d;
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

        /* ================================================ */
        /* PAGINATION                                     */
        /* ================================================ */
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

        .pagination .page-item.disabled .page-link {
            color: #adb5bd;
        }

        /* ================================================ */
        /* TRUNCATE TEXT                                  */
        /* ================================================ */
        .truncate {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
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

            .filter-section .filter-row {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-section .filter-row .filter-group {
                flex-wrap: wrap;
            }

            .filter-section .filter-row .filter-group .form-control,
            .filter-section .filter-row .filter-group .form-select {
                min-width: 100px;
                flex: 1;
            }

            .filter-section .filter-actions {
                margin-left: 0;
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

            .truncate {
                max-width: 100px;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
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

            .filter-section .filter-row .filter-group .form-control,
            .filter-section .filter-row .filter-group .form-select {
                min-width: 80px;
                font-size: 12px;
                height: 34px;
                padding: 4px 8px;
            }

            .filter-section .filter-row .filter-group .btn {
                font-size: 12px;
                height: 34px;
                padding: 4px 10px;
            }

            .truncate {
                max-width: 60px;
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
                        <h1><i class="fas fa-history me-2"></i>Audit Logs</h1>
                        <p>View and monitor all platform activities</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-outline-secondary" onclick="exportLogs()">
                            <i class="fas fa-download me-2"></i> Export
                        </button>
                        <button class="btn btn-outline-danger" onclick="cleanLogs()">
                            <i class="fas fa-trash me-2"></i> Clean Old Logs
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Stats -->
                <div class="stats-row" id="statsRow">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-list"></i></div>
                        <div>
                            <div class="stat-number" id="totalLogs">0</div>
                            <div class="stat-label">Total Logs</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number" id="todayLogs">0</div>
                            <div class="stat-label">Today's Logs</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number" id="uniqueUsers">0</div>
                            <div class="stat-label">Active Users</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="stat-number" id="lastActivity">-</div>
                            <div class="stat-label">Last Activity</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="searchInput"><i class="fas fa-search"></i></label>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search logs..." style="min-width: 180px;">
                        </div>
                        <div class="filter-group">
                            <label for="actionFilter">Action</label>
                            <select class="form-select" id="actionFilter">
                                <option value="">All Actions</option>
                                <option value="create">Create</option>
                                <option value="update">Update</option>
                                <option value="delete">Delete</option>
                                <option value="login">Login</option>
                                <option value="logout">Logout</option>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="assign">Assign</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="moduleFilter">Module</label>
                            <select class="form-select" id="moduleFilter">
                                <option value="">All Modules</option>
                                <option value="tenant">Tenant</option>
                                <option value="user">User</option>
                                <option value="subscription">Subscription</option>
                                <option value="domain">Domain</option>
                                <option value="auth">Auth</option>
                                <option value="system">System</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="tenantFilter">Tenant</label>
                            <select class="form-select" id="tenantFilter">
                                <option value="">All Tenants</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="dateFrom">From</label>
                            <input type="date" class="form-control" id="dateFrom" style="min-width:130px;">
                        </div>
                        <div class="filter-group">
                            <label for="dateTo">To</label>
                            <input type="date" class="form-control" id="dateTo" style="min-width:130px;">
                        </div>
                        <div class="filter-actions">
                            <button class="btn btn-primary" onclick="loadLogs(1)">
                                <i class="fas fa-filter me-1"></i> Apply
                            </button>
                            <button class="btn btn-outline-secondary" onclick="resetFilters()">
                                <i class="fas fa-undo me-1"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Resource</th>
                                <th>Description</th>
                                <th>IP Address</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Loading audit logs...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div class="pagination-wrapper">
                        <div class="info" id="paginationInfo">Showing 0 of 0 logs</div>
                        <nav>
                            <ul class="pagination" id="paginationControls">
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadLogs(1)">First</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadLogs(currentPage - 1)">Prev</a></li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadLogs(currentPage + 1)">Next</a></li>
                                <li class="page-item disabled"><a class="page-link" href="#" onclick="loadLogs(totalPages)">Last</a></li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = 'http://admin.edutrack.local/api/platform';
        const TOKEN = localStorage.getItem('token') || '';
        let currentPage = 1;
        let totalPages = 1;
        let totalRecords = 0;

        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = 'http://localhost:8000/platform/login.php';
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

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function formatDateShort(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
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
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert">
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
        // GET ACTION BADGE
        // ================================================
        function getActionBadge(action) {
            const map = {
                'create': '<span class="badge-action create">Create</span>',
                'update': '<span class="badge-action update">Update</span>',
                'delete': '<span class="badge-action delete">Delete</span>',
                'login': '<span class="badge-action login">Login</span>',
                'logout': '<span class="badge-action logout">Logout</span>',
                'activate': '<span class="badge-action activate">Activate</span>',
                'deactivate': '<span class="badge-action deactivate">Deactivate</span>',
                'assign': '<span class="badge-action assign">Assign</span>'
            };
            return map[action] || '<span class="badge-action default">' + action + '</span>';
        }

        // ================================================
        // GET MODULE BADGE
        // ================================================
        function getModuleBadge(module) {
            const map = {
                'tenant': '<span class="badge-module tenant">Tenant</span>',
                'user': '<span class="badge-module user">User</span>',
                'subscription': '<span class="badge-module subscription">Subscription</span>',
                'domain': '<span class="badge-module domain">Domain</span>',
                'auth': '<span class="badge-module auth">Auth</span>',
                'system': '<span class="badge-module system">System</span>'
            };
            return map[module] || '<span class="badge-module default">' + module + '</span>';
        }

        // ================================================
        // LOAD TENANT DROPDOWN
        // ================================================
        async function loadTenantDropdown() {
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=tenants&action=list`, {
                    headers: getHeaders()
                });

                // Check if response is OK
                if (!response.ok) {
                    console.error('Tenants API returned status:', response.status);
                    // Use mock tenants
                    useMockTenants();
                    return;
                }

                const result = await response.json();
                console.log('Tenants API Response:', result);

                const select = document.getElementById('tenantFilter');
                select.innerHTML = '<option value="">All Tenants</option>';

                let tenants = [];
                if (result.success) {
                    if (Array.isArray(result.data)) {
                        tenants = result.data;
                    } else if (result.data && Array.isArray(result.data.tenants)) {
                        tenants = result.data.tenants;
                    }
                }

                if (tenants.length === 0) {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = '-- No tenants available --';
                    select.appendChild(option);
                } else {
                    tenants.forEach(tenant => {
                        const option = document.createElement('option');
                        option.value = tenant.id;
                        option.textContent = tenant.legal_name || tenant.tenant_name || tenant.name ||
                            'Tenant #' + tenant.id;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading tenants:', error);
                useMockTenants();
            }
        }

        function useMockTenants() {
            const select = document.getElementById('tenantFilter');
            select.innerHTML = '<option value="">All Tenants</option>';
            const option = document.createElement('option');
            option.value = '';
            option.textContent = '-- Use API to load tenants --';
            select.appendChild(option);
        }
        // ================================================
        // LOAD STATS
        // ================================================
        async function loadStats() {
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=audit-logs&stats=true`, {
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success && result.data) {
                    const stats = result.data;
                    document.getElementById('totalLogs').textContent = formatNumber(stats.total || 0);
                    document.getElementById('todayLogs').textContent = formatNumber(stats.today || 0);
                    document.getElementById('uniqueUsers').textContent = formatNumber(stats.unique_users || 0);
                    document.getElementById('lastActivity').textContent = stats.last_activity ? formatDate(stats.last_activity) : 'Never';
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }

        // ================================================
        // LOAD LOGS
        // ================================================
        async function loadLogs(page = 1) {
            currentPage = page;
            const search = document.getElementById('searchInput').value.trim();
            const action = document.getElementById('actionFilter').value;
            const module = document.getElementById('moduleFilter').value;
            const tenantId = document.getElementById('tenantFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            const tbody = document.getElementById('logsTableBody');

            tbody.innerHTML = `
        <tr>
            <td colspan="8" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 text-muted">Loading audit logs...</p>
            </td>
        </tr>
    `;

            try {
                let url = `${API_BASE}/index.php?endpoint=audit-logs&page=${page}`;
                if (search) url += `&search=${encodeURIComponent(search)}`;
                if (action) url += `&action=${encodeURIComponent(action)}`;
                if (module) url += `&module=${encodeURIComponent(module)}`;
                if (tenantId) url += `&tenant_id=${encodeURIComponent(tenantId)}`;
                if (dateFrom) url += `&date_from=${encodeURIComponent(dateFrom)}`;
                if (dateTo) url += `&date_to=${encodeURIComponent(dateTo)}`;

                console.log('Fetching URL:', url);

                const response = await fetch(url, {
                    headers: getHeaders()
                });

                console.log('Response status:', response.status);

                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response:', text);
                    tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        Server returned invalid response. Please check API configuration.
                    </td>
                </tr>
            `;
                    return;
                }

                const result = await response.json();
                console.log('API Response:', result);

                if (result.success && result.data) {
                    const logs = result.data.logs || [];
                    totalRecords = result.data.total || 0;
                    totalPages = result.data.total_pages || 1;

                    if (logs.length === 0) {
                        tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fas fa-history fa-2x d-block mb-2"></i>
                            No audit logs found
                        </td>
                    </tr>
                `;
                    } else {
                        tbody.innerHTML = logs.map((log) => {
                            const actionBadge = getActionBadge(log.action_type);
                            const moduleBadge = getModuleBadge(log.module);
                            const userName = log.username || log.user_name || 'System';
                            const userInfo = log.first_name && log.last_name ?
                                `${log.first_name} ${log.last_name}` : userName;

                            return `
                        <tr>
                            <td style="font-size:12px;color:#6c757d;white-space:nowrap;">
                                ${formatDate(log.created_at)}
                            </td>
                            <td>
                                <div>
                                    <div style="font-weight:500;font-size:13px;">${userInfo}</div>
                                    <div style="font-size:11px;color:#6c757d;">${log.email || ''}</div>
                                </div>
                            </td>
                            <td>${actionBadge}</td>
                            <td>${moduleBadge}</td>
                            <td>
                                <span class="truncate" title="${log.resource || ''}">
                                    ${log.resource || 'N/A'}
                                </span>
                            </td>
                            <td>
                                <span class="truncate" title="${log.description || ''}">
                                    ${log.description || log.resource_id ? 'ID: ' + log.resource_id : 'N/A'}
                                </span>
                            </td>
                            <td style="font-size:12px;color:#6c757d;">${log.ip_address || 'N/A'}</td>
                            <td>
                                <div class="table-actions" style="justify-content:center;">
                                    <button class="btn btn-sm btn-outline-info" onclick="viewLog(${log.id})" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                        }).join('');
                    }

                    updatePagination();

                } else {
                    tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        ${result.message || 'Failed to load logs'}
                    </td>
                </tr>
            `;
                }
            } catch (error) {
                console.error('Error loading logs:', error);
                tbody.innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-4 text-danger">
                    <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                    Error loading logs: ${error.message}
                    <br><small class="text-muted">Check console for details</small>
                </td>
            </tr>
        `;
            }
        }

        // ================================================
        // UPDATE PAGINATION
        // ================================================
        function updatePagination() {
            const info = document.getElementById('paginationInfo');
            const controls = document.getElementById('paginationControls');
            const current = currentPage;
            const total = totalPages;

            const start = (current - 1) * 20 + 1;
            const end = Math.min(current * 20, totalRecords);
            info.textContent = `Showing ${start} to ${end} of ${totalRecords} logs`;

            let html = `
                <li class="page-item ${current <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadLogs(1)">First</a>
                </li>
                <li class="page-item ${current <= 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadLogs(${current - 1})">Prev</a>
                </li>
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
                    <li class="page-item ${i === current ? 'active' : ''}">
                        <a class="page-link" href="#" onclick="loadLogs(${i})">${i}</a>
                    </li>
                `;
            }

            if (endPage < total) {
                html += `<li class="page-item disabled"><a class="page-link">...</a></li>`;
            }

            html += `
                <li class="page-item ${current >= total ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadLogs(${current + 1})">Next</a>
                </li>
                <li class="page-item ${current >= total ? 'disabled' : ''}">
                    <a class="page-link" href="#" onclick="loadLogs(${total})">Last</a>
                </li>
            `;

            controls.innerHTML = html;
        }

        // ================================================
        // RESET FILTERS
        // ================================================
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('actionFilter').value = '';
            document.getElementById('moduleFilter').value = '';
            document.getElementById('tenantFilter').value = '';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            loadLogs(1);
        }

        // ================================================
        // VIEW LOG DETAILS
        // ================================================
        function viewLog(id) {
            // Redirect to view page or show modal
            window.location.href = `/platform/audit/view.php?id=${id}`;
        }

        // ================================================
        // EXPORT LOGS
        // ================================================
        function exportLogs() {
            const search = document.getElementById('searchInput').value.trim();
            const action = document.getElementById('actionFilter').value;
            const module = document.getElementById('moduleFilter').value;
            const tenantId = document.getElementById('tenantFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            let url = `${API_BASE}/index.php?endpoint=audit-logs&export=true`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            if (action) url += `&action=${encodeURIComponent(action)}`;
            if (module) url += `&module=${encodeURIComponent(module)}`;
            if (tenantId) url += `&tenant_id=${encodeURIComponent(tenantId)}`;
            if (dateFrom) url += `&date_from=${encodeURIComponent(dateFrom)}`;
            if (dateTo) url += `&date_to=${encodeURIComponent(dateTo)}`;

            // Open in new tab for download
            window.open(url, '_blank');
        }

        // ================================================
        // CLEAN OLD LOGS
        // ================================================
        async function cleanLogs() {
            const days = prompt('Delete logs older than how many days? (Default: 90)', '90');
            if (days === null) return;

            const daysNum = parseInt(days);
            if (isNaN(daysNum) || daysNum < 1) {
                showAlert('Please enter a valid number of days', 'danger');
                return;
            }

            if (!confirm(`Are you sure you want to delete all logs older than ${daysNum} days? This action cannot be undone.`)) {
                return;
            }

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=audit-logs&clean=${daysNum}`, {
                    method: 'DELETE',
                    headers: getHeaders()
                });
                const result = await response.json();
                if (result.success) {
                    showAlert(`Deleted ${result.data?.deleted || 0} old logs`, 'success');
                    loadStats();
                    loadLogs(currentPage);
                } else {
                    showAlert('✗ ' + (result.message || 'Failed to clean logs'), 'danger');
                }
            } catch (error) {
                console.error('Error cleaning logs:', error);
                showAlert('Error cleaning logs: ' + error.message, 'danger');
            }
        }

        // ================================================
        // ENTER KEY SUPPORT
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('searchInput').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    loadLogs(1);
                }
            });
        });

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadTenantDropdown();
            loadStats();
            loadLogs(1);
        });
    </script>
</body>

</html>