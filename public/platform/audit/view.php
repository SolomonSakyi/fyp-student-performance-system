<?php

/**
 * View Audit Log - View detailed audit log entry
 *
 * @package EduTrack
 * @subpackage Platform\Audit
 * @version 1.0
 * @filepath public/platform/audit/view.php
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
 *   showAlert(), formatDate(), formatJSON(), getActionBadge(),
 *   getModuleBadge(), loadLog(), and the DOMContentLoaded handler are
 *   kept — they are page-scoped and do not conflict with the partial.
 *
 *   No config.php is added by this sweep: the file does not use it.
 *   The PHP block above the doctype reads only $_SESSION['logged_in']
 *   and $_GET['id']. It does not touch the database. The page loads
 *   its single log entry client-side via fetch() against the
 *   /api/platform audit-logs endpoint.
 *
 *   v1.0 (2026-10-08) [API HOST]: The API_BASE constant is changed
 *   from 'http://localhost:8000/api/platform' to
 *   'http://admin.edutrack.local/api/platform', matching the host
 *   that serves this page and matching the confirmed fix on
 *   public/platform/audit/index.php v1.0. The prior value targeted a
 *   local dev server on port 8000 that is not running, producing
 *   net::ERR_CONNECTION_REFUSED on the log-detail fetch. The endpoint
 *   (endpoint=audit-logs&id=<id>), the query string, the headers, the
 *   error branches, and every other line are byte-identical to the
 *   swept file. The logout() redirect string is unchanged.
 *
 *   NOTE on record: audit/index.php links to this file via the
 *   per-row View Details button (viewLog()). Nothing else on the
 *   record links to this file. The audit-logs endpoint this page
 *   calls is not on the record.
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
$pageTitle = 'View Audit Log - Student 360 Platform';
$currentPage = 'audit';

$logId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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

        .badge-action {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
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

        .badge-action.default {
            background: #e9ecef;
            color: #6c757d;
        }

        .badge-module {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
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

        .detail-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
            flex-wrap: wrap;
            gap: 4px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .detail-label {
            width: 160px;
            font-weight: 500;
            color: #6c757d;
            flex-shrink: 0;
            font-size: 13px;
        }

        .detail-row .detail-value {
            flex: 1;
            font-weight: 500;
            color: #1a1a2e;
            font-size: 14px;
            word-break: break-word;
        }

        .json-view {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 12px 16px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
        }

        .loading-spinner {
            text-align: center;
            padding: 60px 20px;
        }

        .loading-spinner .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        .loading-spinner p {
            margin-top: 12px;
            color: #6c757d;
            font-size: 15px;
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

            .card-custom .card-header-custom {
                padding: 12px 16px;
            }

            .card-custom .card-body-custom {
                padding: 14px 16px;
            }

            .detail-row .detail-label {
                width: 120px;
                font-size: 12px;
            }

            .detail-row .detail-value {
                font-size: 13px;
            }

            .json-view {
                font-size: 12px;
                max-height: 200px;
            }

            [class*="col-"] {
                padding-left: 6px;
                padding-right: 6px;
            }

            .row {
                margin: 0 -6px;
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

            .card-custom .card-header-custom h6 {
                font-size: 14px;
            }

            .card-custom .card-body-custom {
                padding: 10px 12px;
            }

            .detail-row .detail-label {
                width: 100px;
                font-size: 11px;
            }

            .detail-row .detail-value {
                font-size: 12px;
            }

            .json-view {
                font-size: 11px;
                max-height: 150px;
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
                        <h1><i class="fas fa-file-alt me-2"></i>Audit Log Details</h1>
                        <p>View detailed audit log entry</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/audit/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </a>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Loading -->
                <div id="loading" class="loading-spinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p>Loading log details...</p>
                </div>

                <!-- View Content -->
                <div id="viewContent" style="display: none;">
                    <!-- Log Info Card -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-info-circle me-2 text-primary"></i>Log Information</h6>
                            <span id="logIdDisplay"></span>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <div class="detail-row">
                                        <span class="detail-label">Date & Time</span>
                                        <span class="detail-value" id="logTime">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">User</span>
                                        <span class="detail-value" id="logUser">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Email</span>
                                        <span class="detail-value" id="logEmail">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">IP Address</span>
                                        <span class="detail-value" id="logIp">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">User Agent</span>
                                        <span class="detail-value" id="logUserAgent" style="font-size:12px;">-</span>
                                    </div>
                                </div>
                                <div class="col-md-6 col-12">
                                    <div class="detail-row">
                                        <span class="detail-label">Action</span>
                                        <span class="detail-value" id="logAction">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Module</span>
                                        <span class="detail-value" id="logModule">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Resource</span>
                                        <span class="detail-value" id="logResource">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Resource ID</span>
                                        <span class="detail-value" id="logResourceId">-</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Tenant</span>
                                        <span class="detail-value" id="logTenant">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Description Card -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-align-left me-2 text-secondary"></i>Description</h6>
                        </div>
                        <div class="card-body-custom">
                            <p id="logDescription" style="margin:0; font-size:14px; line-height:1.8;">-</p>
                        </div>
                    </div>

                    <!-- Data Changes Card -->
                    <div class="card-custom" id="dataChangesCard" style="display:none;">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-code me-2 text-warning"></i>Data Changes</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="row">
                                <div class="col-md-6 col-12" id="oldDataColumn">
                                    <h6 class="text-muted" style="font-size:13px;">Old Data</h6>
                                    <div class="json-view" id="oldData"></div>
                                </div>
                                <div class="col-md-6 col-12" id="newDataColumn">
                                    <h6 class="text-muted" style="font-size:13px;">New Data</h6>
                                    <div class="json-view" id="newData"></div>
                                </div>
                            </div>
                        </div>
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
        const logId = <?php echo $logId; ?>;

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

        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function formatJSON(obj) {
            if (!obj) return 'No data';
            if (typeof obj === 'string') {
                try {
                    obj = JSON.parse(obj);
                } catch (e) {
                    return obj;
                }
            }
            return JSON.stringify(obj, null, 2);
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
                'activate': '<span class="badge-action create">Activate</span>',
                'deactivate': '<span class="badge-action delete">Deactivate</span>',
                'assign': '<span class="badge-action update">Assign</span>'
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
        // LOAD LOG
        // ================================================
        async function loadLog() {
            if (!logId || logId === 0) {
                document.getElementById('loading').innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        <h5>Invalid Log ID</h5>
                        <a href="/platform/audit/index.php" class="btn btn-primary">Back to Audit Logs</a>
                    </div>
                `;
                return;
            }

            try {
                document.getElementById('loading').style.display = 'block';
                document.getElementById('viewContent').style.display = 'none';

                const response = await fetch(`${API_BASE}/index.php?endpoint=audit-logs&id=${logId}`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                if (result.success && result.data) {
                    const log = result.data;

                    // Basic info
                    document.getElementById('logTime').textContent = formatDate(log.created_at);
                    document.getElementById('logIp').textContent = log.ip_address || 'N/A';
                    document.getElementById('logUserAgent').textContent = log.user_agent || 'N/A';
                    document.getElementById('logIdDisplay').textContent = `#${log.id}`;

                    // User info
                    const userName = log.username || log.user_name || 'System';
                    const fullName = log.first_name && log.last_name ?
                        `${log.first_name} ${log.last_name}` : userName;
                    document.getElementById('logUser').textContent = fullName;
                    document.getElementById('logEmail').textContent = log.email || 'N/A';

                    // Action & Module
                    document.getElementById('logAction').innerHTML = getActionBadge(log.action_type);
                    document.getElementById('logModule').innerHTML = getModuleBadge(log.module);
                    document.getElementById('logResource').textContent = log.resource || 'N/A';
                    document.getElementById('logResourceId').textContent = log.resource_id || 'N/A';
                    document.getElementById('logTenant').textContent = log.tenant_name || 'N/A';

                    // Description
                    document.getElementById('logDescription').textContent = log.description || 'No description available';

                    // Data changes
                    if (log.old_data || log.new_data) {
                        document.getElementById('dataChangesCard').style.display = 'block';
                        document.getElementById('oldData').textContent = formatJSON(log.old_data);
                        document.getElementById('newData').textContent = formatJSON(log.new_data);

                        if (!log.old_data) {
                            document.getElementById('oldDataColumn').style.display = 'none';
                        }
                        if (!log.new_data) {
                            document.getElementById('newDataColumn').style.display = 'none';
                        }
                    }

                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('viewContent').style.display = 'block';

                } else {
                    document.getElementById('loading').innerHTML = `
                        <div class="text-center py-4 text-danger">
                            <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                            <h5>Error</h5>
                            <p>${result.message || 'Log not found'}</p>
                            <a href="/platform/audit/index.php" class="btn btn-primary">Back to Audit Logs</a>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading log:', error);
                document.getElementById('loading').innerHTML = `
                    <div class="text-center py-4 text-danger">
                        <i class="fas fa-exclamation-circle fa-2x d-block mb-2"></i>
                        <h5>Error</h5>
                        <p>Error loading log: ${error.message}</p>
                        <a href="/platform/audit/index.php" class="btn btn-primary">Back to Audit Logs</a>
                    </div>
                `;
            }
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            loadLog();
        });
    </script>
</body>

</html>