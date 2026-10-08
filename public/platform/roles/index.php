<?php

/**
 * Roles Management - List all platform roles
 *
 * @package EduTrack
 * @subpackage Platform\Roles
 * @version 1.0
 * @filepath public/platform/roles/index.php
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
 *   $pendingApprovals = 0, $currentUser, and $userAvatar are set
 *   explicitly before the partial is required.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler, resize handler,
 *   logout(), showToast(), deleteRole(), and the DOMContentLoaded
 *   handler are kept — they are page-scoped and do not conflict
 *   with the partial.
 *
 *   The page's server-side session auth, its hybrid client-side
 *   token model, its API_BASE construction, its platform_roles
 *   query with the correlated platform_user_roles count subquery,
 *   its four stat cards, its roles table, its /api/platform/...
 *   DELETE call, its /api/auth/token fetch fallback, and its
 *   localStorage reads are all byte-identical to the previous
 *   version.
 *
 *   NOTE on record: nothing on the record links to
 *   /platform/roles/. This file reads from 'platform_roles';
 *   the users/ folder reads from 'roles'. Those are two different
 *   table names on the record. The API endpoints this file calls
 *   (/api/platform/index.php?endpoint=roles&action=delete&id=<id>
 *   and /api/auth/token) are not on the record.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'. The
 *   config.php require is not added by this sweep.
 */

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
$pageTitle = 'Roles Management - Student 360 Platform';
$currentPage = '';

// Get user info
$userName = $_SESSION['user_name'] ?? 'Admin';
$userInitial = strtoupper(substr($userName, 0, 1));

// Get API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

// ============================================================
// LOAD ROLES FROM DATABASE (Fallback if API fails)
// ============================================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

$db = DatabaseHelper::getInstance();

// Variables read by the sidebar partial
$currentUser = $userName;
$userAvatar = $userInitial;
$pendingApprovals = 0;

$roles = $db->fetchAll(
    "SELECT r.id, r.role_name, r.role_code, r.description, r.is_system, r.is_active,
            r.created_at, r.updated_at,
            (SELECT COUNT(*) FROM platform_user_roles WHERE role_id = r.id) as user_count
     FROM platform_roles r
     WHERE r.deleted_at IS NULL
     ORDER BY r.is_system DESC, r.role_name ASC"
);
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
            padding: 0;
        }

        .table {
            margin-bottom: 0;
        }

        .table th {
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 2px solid #f0f2f5;
            padding: 12px 16px;
        }

        .table td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .role-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .role-code {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: #6c757d;
            background: #f8f9fa;
            padding: 2px 10px;
            border-radius: 4px;
        }

        .status-badge {
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge.system {
            background: #cce5ff;
            color: #004085;
        }

        .btn-action {
            padding: 4px 10px;
            margin: 0 2px;
            border-radius: 6px;
            font-size: 13px;
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

        .empty-state {
            padding: 60px 20px;
            text-align: center;
        }

        .empty-state i {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #6c757d;
            margin-bottom: 16px;
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

            .table-responsive .table th,
            .table-responsive .table td {
                padding: 8px 10px;
                font-size: 13px;
                white-space: nowrap;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: 10px 12px;
            }

            .table-responsive .table th,
            .table-responsive .table td {
                padding: 6px 8px;
                font-size: 12px;
            }

            .topbar .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .topbar h4 {
                font-size: 15px;
            }

            .status-badge {
                font-size: 10px;
                padding: 2px 8px;
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
                        <h4><i class="fas fa-user-tag"></i> Roles Management</h4>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <a href="/platform/roles/create.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Create Role
                        </a>
                        <div class="user-avatar"><?php echo $userInitial; ?></div>
                        <span class="d-none d-sm-inline text-muted small"><?php echo htmlspecialchars($userName); ?></span>
                    </div>
                </div>

                <div class="content-area">
                    <!-- Stats Row -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3 col-6">
                            <div class="card-custom">
                                <div class="card-body-custom" style="padding: 16px 20px;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small">Total Roles</div>
                                            <h4 class="mb-0"><?php echo count($roles); ?></h4>
                                        </div>
                                        <div class="bg-primary bg-opacity-10 rounded p-2">
                                            <i class="fas fa-user-tag text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card-custom">
                                <div class="card-body-custom" style="padding: 16px 20px;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small">System Roles</div>
                                            <h4 class="mb-0">
                                                <?php
                                                $systemCount = 0;
                                                foreach ($roles as $r) {
                                                    if ($r['is_system'] ?? 0) $systemCount++;
                                                }
                                                echo $systemCount;
                                                ?>
                                            </h4>
                                        </div>
                                        <div class="bg-info bg-opacity-10 rounded p-2">
                                            <i class="fas fa-star text-info"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card-custom">
                                <div class="card-body-custom" style="padding: 16px 20px;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small">Custom Roles</div>
                                            <h4 class="mb-0"><?php echo count($roles) - $systemCount; ?></h4>
                                        </div>
                                        <div class="bg-secondary bg-opacity-10 rounded p-2">
                                            <i class="fas fa-user-cog text-secondary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card-custom">
                                <div class="card-body-custom" style="padding: 16px 20px;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="text-muted small">Total Assignments</div>
                                            <h4 class="mb-0">
                                                <?php
                                                $totalUsers = 0;
                                                foreach ($roles as $r) {
                                                    $totalUsers += ($r['user_count'] ?? 0);
                                                }
                                                echo $totalUsers;
                                                ?>
                                            </h4>
                                        </div>
                                        <div class="bg-success bg-opacity-10 rounded p-2">
                                            <i class="fas fa-users text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Roles Table -->
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-list me-2 text-primary"></i> All Roles</h6>
                            <span class="text-muted small"><?php echo count($roles); ?> role(s) found</span>
                        </div>
                        <div class="card-body-custom">
                            <?php if (empty($roles)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-user-tag"></i>
                                    <h5>No Roles Found</h5>
                                    <p class="text-muted">Create your first role to get started.</p>
                                    <a href="/platform/roles/create.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i> Create Role
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th style="width: 50px;">#</th>
                                                <th>Role Name</th>
                                                <th>Code</th>
                                                <th>Type</th>
                                                <th>Permissions</th>
                                                <th>Users</th>
                                                <th>Status</th>
                                                <th style="width: 120px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="rolesBody">
                                            <?php foreach ($roles as $index => $role): ?>
                                                <tr>
                                                    <td><?php echo $index + 1; ?></td>
                                                    <td>
                                                        <span class="role-name"><?php echo htmlspecialchars($role['role_name']); ?></span>
                                                        <?php if (!empty($role['description'])): ?>
                                                            <div class="text-muted small"><?php echo htmlspecialchars(substr($role['description'], 0, 60)) . (strlen($role['description']) > 60 ? '...' : ''); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><code class="role-code"><?php echo htmlspecialchars($role['role_code']); ?></code></td>
                                                    <td>
                                                        <?php if ($role['is_system'] ?? 0): ?>
                                                            <span class="status-badge system"><i class="fas fa-star me-1"></i> System</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Custom</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-primary">0 permissions</span>
                                                        <small class="text-muted d-block">(via API)</small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info"><?php echo $role['user_count'] ?? 0; ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($role['is_active'] ?? 1): ?>
                                                            <span class="status-badge active"><i class="fas fa-circle me-1" style="font-size: 8px;"></i> Active</span>
                                                        <?php else: ?>
                                                            <span class="status-badge inactive"><i class="fas fa-circle me-1" style="font-size: 8px;"></i> Inactive</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!($role['is_system'] ?? 0)): ?>
                                                            <a href="/platform/roles/edit.php?id=<?php echo $role['id']; ?>" class="btn btn-sm btn-outline-secondary btn-action" title="Edit">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <button class="btn btn-sm btn-outline-danger btn-action" onclick="deleteRole(<?php echo $role['id']; ?>)" title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="text-muted small"><i class="fas fa-lock me-1"></i> System</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                                    <small class="text-muted">Showing <?php echo count($roles); ?> roles</small>
                                    <small class="text-muted">
                                        <i class="fas fa-lock me-1"></i> System roles cannot be deleted
                                    </small>
                                </div>
                            <?php endif; ?>
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
            // DELETE ROLE
            // ============================================================
            window.deleteRole = async function(id) {
                if (!confirm('⚠️ Delete this role?\n\nThis action cannot be undone.\n\nAre you sure?')) {
                    return;
                }

                try {
                    const response = await fetch(API_BASE + '/index.php?endpoint=roles&action=delete&id=' + id, {
                        method: 'DELETE',
                        headers: getHeaders()
                    });

                    const result = await response.json();

                    if (result.success) {
                        showToast('success', 'Role deleted successfully');
                        // Reload page to refresh the list
                        setTimeout(function() {
                            window.location.reload();
                        }, 500);
                    } else {
                        showToast('danger', result.message || 'Deletion failed');
                    }
                } catch (error) {
                    console.error('Delete error:', error);
                    showToast('danger', 'Error: ' + error.message);
                }
            };

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

                if (!localStorage.getItem('platform_token')) {
                    window.location.href = '/platform/login.php';
                    return;
                }
            });

        })();
    </script>
</body>

</html>