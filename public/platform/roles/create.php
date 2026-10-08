<?php

/**
 * Create Role - Create a new platform role
 *
 * @package EduTrack
 * @subpackage Platform\Roles
 * @version 1.0
 * @filepath public/platform/roles/create.php
 *
 * v1.0 change (2026-10-07) [SWEEP X-1 + SIDEBAR]:
 *   Platform-roles sweep, X-1 in full, plus sidebar reconciliation.
 *
 *   X-1: one visible "EduTrack" string (the sidebar brand heading)
 *   is superseded by the sidebar reconciliation below, which
 *   replaces the entire inline sidebar with the shared partial.
 *   The $pageTitle is realigned to the "Student 360 Platform"
 *   suffix convention used across the swept platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 11 items
 *   across 4 groups (Main, Institution, Management, System), brand
 *   heading "EduTrack", width 260px — is replaced by a single
 *   require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System), folder
 *   paths for Tenants and Settings, no Register Tenant item, plus
 *   the mobile toggle, the sidebar footer, and the toggleSidebar()
 *   JS.
 *
 *   The v2.0 inline sidebar marked /platform/users/index.php as
 *   active on this Roles page — a copy-paste mismatch. That
 *   mismatch is gone with the inline sidebar. The canonical
 *   partial has no Roles item, so $currentPage is set to '' and no
 *   sidebar item is marked active on this page.
 *
 *   $projectRoot is defined at the top of the file because the
 *   v2.0 file did not define it and the partial require needs it.
 *   $pendingApprovals = 0, $currentUser, and $userAvatar are set
 *   explicitly before the partial is required.
 *
 *   No config.php and no DatabaseHelper.php are added by this
 *   sweep: the file does not open a database connection and every
 *   write goes through the API endpoints.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler, resize handler,
 *   logout(), showToast(), escapeHtml(), selectAllPermissions(),
 *   the two role-code auto-generation listeners, loadPermissions(),
 *   the form-submit handler, and the DOMContentLoaded handler are
 *   kept — they are page-scoped and do not conflict with the
 *   partial.
 *
 *   The page's server-side session auth, its hybrid client-side
 *   token model, its API_BASE construction, its /api/platform/...
 *   ?action=permissions fetch, its /api/platform/...?action=create
 *   POST, its /api/auth/token fetch fallback, its localStorage
 *   reads, its permission-group rendering, its form validation,
 *   and its entire CSS block are all byte-identical to the
 *   previous version.
 *
 *   NOTE on record: nothing outside roles/ links to this file.
 *   The two API endpoints this file calls are not on the record.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

session_start();

// ============================================================
// AUTHENTICATION
// ============================================================
$isLoggedIn = isset($_SESSION['user_id']) || isset($_GET['token']);
if (!$isLoggedIn) {
    header('Location: /platform/login.php');
    exit;
}

// Check if user is super admin
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/index.php');
    exit;
}

// ============================================================
// PAGE SETUP
// ============================================================
$pageTitle = 'Create Role - Student 360 Platform';
$currentPage = '';

// Get user info
$userName = $_SESSION['user_name'] ?? 'Admin';
$userInitial = strtoupper(substr($userName, 0, 1));

// Variables read by the sidebar partial
$currentUser = $userName;
$userAvatar = $userInitial;
$pendingApprovals = 0;

// Get API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
        }

        /* ============================================================
           SIDEBAR
           ============================================================ */
        .sidebar {
            background: #1a1a2e !important;
            min-height: 100vh;
            position: fixed;
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar .sidebar-header {
            padding: 25px 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 20px;
            color: #fff;
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
            margin-top: 8px;
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
            font-size: 14px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
            color: #fff;
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

        /* ============================================================
           MAIN CONTENT
           ============================================================ */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            background: #fff;
            padding: 12px 25px;
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 999;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar h4 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
            font-size: 20px;
        }

        .topbar h4 i {
            color: #4facfe;
            margin-right: 10px;
        }

        .topbar .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 15px;
        }

        .content-area {
            padding: 24px 32px 40px;
        }

        /* ============================================================
           CARDS
           ============================================================ */
        .card-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            background: #fff;
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
            padding: 24px;
        }

        .section-title {
            font-weight: 600;
            color: #1a1a2e;
            border-bottom: 2px solid #f0f2f5;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .section-title i {
            color: #4facfe;
            margin-right: 8px;
        }

        /* ============================================================
           FORMS
           ============================================================ */
        .form-label {
            font-weight: 500;
            font-size: 14px;
            color: #1a1a2e;
        }

        .required {
            color: #dc3545;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 10px 16px;
            border: 2px solid #e9ecef;
            font-size: 14px;
            transition: all 0.3s;
            height: 46px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
        }

        textarea.form-control {
            height: auto;
        }

        .code-preview {
            font-family: 'Courier New', monospace;
            color: #6c757d;
            font-size: 13px;
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

        .btn-primary:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
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

        /* ============================================================
           PERMISSION GROUPS
           ============================================================ */
        .permission-group {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.2s;
        }

        .permission-group:hover {
            border-color: #4facfe;
            background: #f8fcff;
        }

        .permission-group .group-title {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 10px;
        }

        .permission-group .group-title i {
            color: #4facfe;
        }

        .form-check-input:checked {
            background-color: #4facfe;
            border-color: #4facfe;
        }

        /* ============================================================
           TOAST
           ============================================================ */
        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast-custom {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            border-left: 4px solid #4facfe;
            padding: 14px 18px;
            margin-top: 8px;
            animation: slideIn 0.3s ease;
            min-width: 280px;
        }

        .toast-custom.success {
            border-left-color: #28a745;
        }

        .toast-custom.danger {
            border-left-color: #dc3545;
        }

        .toast-custom.warning {
            border-left-color: #ffc107;
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

        /* ============================================================
           RESPONSIVE
           ============================================================ */
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
            }

            .content-area {
                padding: 16px;
            }
        }

        @media (max-width: 768px) {
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
            }

            .topbar {
                flex-wrap: wrap;
                gap: 10px;
            }

            .content-area {
                padding: 16px;
            }

            .topbar h4 {
                font-size: 17px;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 10px 12px;
            }

            .card-custom .card-body-custom {
                padding: 16px;
            }

            .permission-group {
                padding: 12px;
            }

            .topbar .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 12px;
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
                <div class="topbar">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-link d-md-none me-2" type="button" onclick="toggleSidebar()">
                            <i class="fas fa-bars fs-5"></i>
                        </button>
                        <h4><i class="fas fa-plus-circle"></i> Create Role</h4>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="user-avatar"><?php echo $userInitial; ?></div>
                        <span class="d-none d-sm-inline text-muted small"><?php echo htmlspecialchars($userName); ?></span>
                    </div>
                </div>

                <div class="content-area">
                    <div class="card-custom">
                        <div class="card-body-custom">
                            <!-- Alerts -->
                            <div id="successMessage" class="alert alert-success" style="display: none;">
                                <i class="fas fa-check-circle"></i> <span id="successText"></span>
                            </div>
                            <div id="errorMessage" class="alert alert-danger" style="display: none;">
                                <i class="fas fa-exclamation-circle"></i> <span id="errorText"></span>
                            </div>

                            <form id="roleForm" novalidate>
                                <!-- Role Information -->
                                <h6 class="section-title"><i class="fas fa-info-circle"></i> Role Information</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="name" class="form-label fw-semibold">Role Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="name" placeholder="e.g., Platform Manager" required>
                                        <div class="invalid-feedback">Please enter a role name</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="code" class="form-label fw-semibold">Role Code <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="code" placeholder="e.g., platform_manager" required>
                                        <small class="text-muted">Unique identifier (lowercase, underscore separated)</small>
                                        <div class="code-preview" id="codePreview"></div>
                                    </div>
                                    <div class="col-12">
                                        <label for="description" class="form-label fw-semibold">Description</label>
                                        <textarea class="form-control" id="description" rows="2" placeholder="Role description"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" id="is_active" checked>
                                            <label class="form-check-label fw-semibold" for="is_active">Active</label>
                                            <small class="d-block text-muted">Inactive roles cannot be assigned to users</small>
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <!-- Permissions -->
                                <h6 class="section-title"><i class="fas fa-lock"></i> Permissions</h6>
                                <p class="text-muted small">Select the permissions you want to assign to this role</p>

                                <div id="permissionsContainer">
                                    <div class="text-center py-3">
                                        <i class="fas fa-spinner fa-spin text-primary"></i>
                                        <span class="text-muted ms-2">Loading permissions...</span>
                                    </div>
                                </div>

                                <!-- Permission Actions -->
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllPermissions(true)">
                                        <i class="fas fa-check-double"></i> Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllPermissions(false)">
                                        <i class="fas fa-times"></i> Deselect All
                                    </button>
                                </div>

                                <hr class="my-4">

                                <!-- Submit -->
                                <div class="d-flex gap-2 flex-wrap">
                                    <button type="submit" class="btn btn-primary px-4" id="submitBtn">
                                        <i class="fas fa-check"></i> Create Role
                                    </button>
                                    <a href="/platform/roles/index.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            'use strict';

            // ============================================================
            // API CONFIGURATION
            // ============================================================
            const API_BASE = '<?php echo $apiBase; ?>';

            // ============================================================
            // AUTHENTICATION
            // ============================================================
            let token = localStorage.getItem('platform_token');
            if (!token) {
                const urlParams = new URLSearchParams(window.location.search);
                token = urlParams.get('token');
                if (token) {
                    localStorage.setItem('platform_token', token);
                } else {
                    // Try to get token from session
                    fetch('/api/auth/token')
                        .then(response => response.json())
                        .then(data => {
                            if (data.token) {
                                localStorage.setItem('platform_token', data.token);
                            }
                        })
                        .catch(() => {});
                }
            }

            function getHeaders() {
                const headers = {
                    'Content-Type': 'application/json'
                };
                const token = localStorage.getItem('platform_token');
                if (token) {
                    headers['Authorization'] = 'Bearer ' + token;
                }
                return headers;
            }

            // ============================================================
            // SIDEBAR TOGGLE
            // ============================================================
            // toggleSidebar() is provided by the partial at
            // app/views/partials/platform-sidebar.php.

            document.addEventListener('click', function(event) {
                const sidebar = document.getElementById('sidebar');
                const toggle = document.getElementById('sidebarToggle');
                if (window.innerWidth <= 768) {
                    if (sidebar && toggle && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                        sidebar.classList.remove('open');
                    }
                }
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    const sb = document.getElementById('sidebar');
                    if (sb) sb.classList.remove('open');
                }
            });

            // ============================================================
            // LOGOUT
            // ============================================================
            window.logout = function() {
                if (confirm('Are you sure you want to logout?')) {
                    localStorage.removeItem('platform_token');
                    localStorage.removeItem('platform_user');
                    localStorage.removeItem('platform_refresh_token');
                    window.location.href = '/platform/logout.php';
                }
            };

            // ============================================================
            // TOAST NOTIFICATIONS
            // ============================================================
            window.showToast = function(type, message) {
                const container = document.getElementById('toastContainer');
                const colors = {
                    success: '#28a745',
                    danger: '#dc3545',
                    warning: '#ffc107',
                    info: '#4facfe'
                };
                const icons = {
                    success: 'fa-check-circle',
                    danger: 'fa-exclamation-circle',
                    warning: 'fa-exclamation-triangle',
                    info: 'fa-info-circle'
                };
                const toast = document.createElement('div');
                toast.className = 'toast-custom ' + type;
                toast.innerHTML =
                    '<div class="d-flex align-items-center">' +
                    '<i class="fas ' + (icons[type] || 'fa-info-circle') + ' me-2" style="color: ' + (colors[type] || '#4facfe') + ';"></i>' +
                    '<span>' + message + '</span>' +
                    '<button class="btn btn-sm btn-link ms-auto text-secondary" onclick="this.closest(\'.toast-custom\').remove()">' +
                    '<i class="fas fa-times"></i></button>' +
                    '</div>';
                container.appendChild(toast);
                setTimeout(function() {
                    if (toast.parentNode) toast.remove();
                }, 5000);
            };

            // ============================================================
            // UTILITY FUNCTIONS
            // ============================================================
            function escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            window.selectAllPermissions = function(select) {
                document.querySelectorAll('.permission-check').forEach(function(cb) {
                    cb.checked = select;
                });
            };

            // ============================================================
            // AUTO-GENERATE ROLE CODE
            // ============================================================
            document.getElementById('name').addEventListener('input', function() {
                const name = this.value.trim();
                const codeInput = document.getElementById('code');
                const preview = document.getElementById('codePreview');

                if (name && !codeInput.value) {
                    const generated = name.toLowerCase()
                        .replace(/[^a-z0-9]/g, '_')
                        .replace(/_+/g, '_')
                        .replace(/^_|_$/g, '');
                    preview.textContent = 'Suggestion: ' + generated;
                    preview.style.color = '#28a745';
                } else if (codeInput.value) {
                    preview.textContent = '';
                } else {
                    preview.textContent = '';
                }
            });

            document.getElementById('code').addEventListener('input', function() {
                let value = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_');
                if (value !== this.value) {
                    this.value = value;
                }
                const preview = document.getElementById('codePreview');
                if (value) {
                    preview.textContent = 'Code: ' + value;
                    preview.style.color = '#6c757d';
                } else {
                    preview.textContent = '';
                }
            });

            // ============================================================
            // LOAD PERMISSIONS
            // ============================================================
            async function loadPermissions() {
                const container = document.getElementById('permissionsContainer');

                try {
                    const response = await fetch(API_BASE + '/index.php?endpoint=roles&action=permissions', {
                        headers: getHeaders()
                    });
                    const result = await response.json();

                    if (result.success && result.data) {
                        const permissions = result.data;
                        const grouped = {};

                        for (let i = 0; i < permissions.length; i++) {
                            const p = permissions[i];
                            const moduleName = p.module || 'General';
                            if (!grouped[moduleName]) {
                                grouped[moduleName] = [];
                            }
                            grouped[moduleName].push(p);
                        }

                        let html = '';
                        const moduleNames = Object.keys(grouped).sort();

                        if (moduleNames.length === 0) {
                            html = '<div class="text-center text-muted py-3">No permissions found</div>';
                        }

                        for (let m = 0; m < moduleNames.length; m++) {
                            const moduleName = moduleNames[m];
                            const perms = grouped[moduleName];
                            const displayName = moduleName.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                                return l.toUpperCase();
                            });

                            html += '<div class="permission-group">';
                            html += '<div class="group-title"><i class="fas fa-cog me-1"></i> ' +
                                escapeHtml(displayName) + '</div>';
                            html += '<div class="row g-2">';

                            for (let j = 0; j < perms.length; j++) {
                                const perm = perms[j];
                                html += '<div class="col-md-4 col-lg-3">';
                                html += '<div class="form-check">';
                                html += '<input class="form-check-input permission-check" type="checkbox" ' +
                                    'value="' + perm.id + '" id="perm_' + perm.id + '">';
                                html += '<label class="form-check-label small" for="perm_' + perm.id + '">' +
                                    escapeHtml(perm.name) + '</label>';
                                html += '</div></div>';
                            }

                            html += '</div></div>';
                        }

                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<div class="text-center text-danger py-3">' +
                            (result.message || 'Error loading permissions') + '</div>';
                    }
                } catch (error) {
                    console.error('Error loading permissions:', error);
                    container.innerHTML =
                        '<div class="text-center text-danger py-3">Failed to load permissions. Please refresh the page.</div>';
                }
            }

            // ============================================================
            // FORM SUBMISSION
            // ============================================================
            document.getElementById('roleForm').addEventListener('submit', async function(e) {
                e.preventDefault();

                const btn = document.getElementById('submitBtn');
                const errorDiv = document.getElementById('errorMessage');
                const errorText = document.getElementById('errorText');
                const successDiv = document.getElementById('successMessage');
                const successText = document.getElementById('successText');

                // Reset UI
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                document.querySelectorAll('.is-invalid').forEach(function(el) {
                    el.classList.remove('is-invalid');
                });

                // Validate
                const nameInput = document.getElementById('name');
                const codeInput = document.getElementById('code');
                let isValid = true;

                if (!nameInput.value.trim()) {
                    nameInput.classList.add('is-invalid');
                    isValid = false;
                }
                if (!codeInput.value.trim()) {
                    codeInput.classList.add('is-invalid');
                    isValid = false;
                }

                if (!isValid) {
                    showToast('warning', 'Please fill in all required fields');
                    return;
                }

                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Creating...';
                btn.disabled = true;

                // Collect permissions
                const permissions = [];
                document.querySelectorAll('.permission-check:checked').forEach(function(cb) {
                    permissions.push(parseInt(cb.value));
                });

                const data = {
                    name: nameInput.value.trim(),
                    code: codeInput.value.trim().toLowerCase().replace(/\s+/g, '_'),
                    description: document.getElementById('description').value.trim(),
                    is_active: document.getElementById('is_active').checked ? 1 : 0,
                    permissions: permissions
                };

                try {
                    const response = await fetch(API_BASE + '/index.php?endpoint=roles&action=create', {
                        method: 'POST',
                        headers: getHeaders(),
                        body: JSON.stringify(data)
                    });

                    const result = await response.json();

                    if (result.success) {
                        successText.textContent = result.message || 'Role created successfully!';
                        successDiv.style.display = 'block';
                        showToast('success', 'Role created successfully!');
                        setTimeout(function() {
                            window.location.href = '/platform/roles/index.php';
                        }, 2000);
                    } else {
                        errorText.textContent = result.message || 'Creation failed';
                        if (result.errors) {
                            if (typeof result.errors === 'object') {
                                const msgs = [];
                                for (const key in result.errors) {
                                    if (result.errors.hasOwnProperty(key)) {
                                        msgs.push(result.errors[key]);
                                    }
                                }
                                if (msgs.length > 0) {
                                    errorText.textContent += ': ' + msgs.join(', ');
                                }
                            }
                        }
                        errorDiv.style.display = 'block';
                        showToast('danger', errorText.textContent);
                    }
                } catch (error) {
                    console.error('Submit error:', error);
                    errorText.textContent = 'Connection error: ' + error.message;
                    errorDiv.style.display = 'block';
                    showToast('danger', errorText.textContent);
                } finally {
                    btn.innerHTML = '<i class="fas fa-check"></i> Create Role';
                    btn.disabled = false;
                }
            });

            // ============================================================
            // INITIALIZE
            // ============================================================
            document.addEventListener('DOMContentLoaded', function() {
                // Load user info from localStorage
                const userStr = localStorage.getItem('platform_user');
                if (userStr) {
                    try {
                        const user = JSON.parse(userStr);
                        const nameEl = document.getElementById('userName');
                        const avatarEl = document.getElementById('userAvatar');
                        if (nameEl) nameEl.textContent = user.first_name || 'Admin';
                        if (avatarEl) avatarEl.textContent = (user.first_name || 'A').charAt(0);
                    } catch (e) {
                        console.error('Error parsing user:', e);
                    }
                }

                loadPermissions();
            });

        })();
    </script>
</body>

</html>