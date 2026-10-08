<?php

/**
 * Users Management - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Users
 * @filepath public/platform/users/index.php
 * @version 1.0
 *
 * v1.0 change (2026-10-07) [SWEEP X-1 + SIDEBAR]:
 *   Platform-users sweep, X-1 in full, plus sidebar reconciliation.
 *
 *   X-1: one visible "EduTrack" string (the sidebar brand heading)
 *   is superseded by the sidebar reconciliation below, which
 *   replaces the entire inline sidebar with the shared partial.
 *   The $pageTitle is realigned to the "Student 360 Platform"
 *   suffix convention used across the swept platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 7 items across
 *   4 groups (Main, Management, Users, System), brand heading
 *   "EduTrack", width 230px — is replaced by a single require_once
 *   of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System), folder
 *   paths for Tenants and Settings, no Register Tenant item, plus
 *   the mobile toggle, the sidebar footer, and the toggleSidebar()
 *   JS.
 *
 *   $currentPage = 'users' is preserved; it matches the canonical
 *   partial's Users key, so the canonical sidebar's Users item is
 *   marked active on this page. $pendingApprovals = 0,
 *   $currentUser, and $userAvatar are set explicitly before the
 *   partial is required.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler, resize handler,
 *   applyFilters(), resetFilters(), the searchInput keyup listener,
 *   and confirmDelete() are kept — they are page-scoped and do not
 *   conflict with the partial.
 *
 *   The page's server-side session auth, its users query, its
 *   tenants query, its roles query, its filters, its users table,
 *   and its delete modal are all byte-identical to the previous
 *   version.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/config/config.php';

session_start();

// Check authentication and super admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$pageTitle = 'Users - Student 360 Platform';
$currentPage = 'users';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userInitial = strtoupper(substr($_SESSION['first_name'] ?? 'S', 0, 1));

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
require_once $projectRoot . '/app/views/partials/platform-sidebar.php';
$db = DatabaseHelper::getInstance();

// Variables read by the sidebar partial
$currentUser = $userName;
$userAvatar = $userInitial;
$pendingApprovals = 0;

// Get filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$tenantFilter = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
$roleFilter = isset($_GET['role_id']) ? (int)$_GET['role_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Get all tenants for filter dropdown
$tenants = $db->fetchAll("SELECT id, tenant_name FROM tenants WHERE deleted_at IS NULL ORDER BY tenant_name");

// Get all roles
$roles = $db->fetchAll("SELECT id, role_name FROM roles ORDER BY role_name");

// Build query
$sql = "SELECT u.*, t.tenant_name,
        GROUP_CONCAT(r.role_name SEPARATOR ', ') as role_names
        FROM platform_users u
        LEFT JOIN tenants t ON u.tenant_id = t.id
        LEFT JOIN platform_user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE u.deleted_at IS NULL";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if ($tenantFilter > 0) {
    $sql .= " AND u.tenant_id = ?";
    $params[] = $tenantFilter;
}
if ($statusFilter === 'active') {
    $sql .= " AND u.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND u.is_active = 0";
}

$sql .= " GROUP BY u.id ORDER BY u.created_at DESC";
$users = $db->fetchAll($sql, $params);

$statuses = ['active' => 'Active', 'inactive' => 'Inactive'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #f0f2f5;
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
            padding-left: 8px;
            padding-right: 8px;
        }

        .sidebar {
            background: #1a1a2e !important;
            min-height: 100vh;
            position: fixed;
            width: 230px;
            left: 0;
            top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar .sidebar-header {
            padding: 20px 18px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 18px;
            color: #fff;
            margin: 0;
        }

        .sidebar .sidebar-header h4 i {
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 11px;
        }

        .sidebar .nav {
            padding: 12px 10px;
        }

        .sidebar .nav-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.3);
            padding: 0 10px 6px;
            font-weight: 600;
            margin-top: 6px;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 8px 14px;
            border-radius: 8px;
            margin: 1px 0;
            transition: all 0.3s;
            font-size: 13px;
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
            width: 20px;
            text-align: center;
            margin-right: 10px;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sidebar .nav-link span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 14px 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .sidebar .sidebar-footer .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 13px;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.4);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.4);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            color: #ff6b6b;
        }

        .main-content {
            margin-left: 230px;
            min-height: 100vh;
            width: calc(100% - 230px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .topbar {
            background: #fff;
            padding: 10px 20px;
            border-bottom: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 999;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .topbar h4 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .topbar h4 i {
            color: #4facfe;
        }

        .topbar .topbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .topbar .topbar-actions .btn {
            font-size: 12px;
            padding: 5px 12px;
            white-space: nowrap;
        }

        .content-area {
            padding: 16px 20px 30px;
            max-width: 100%;
            overflow-x: hidden;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-header h2 {
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
        }

        .filter-section {
            background: #fff;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 12px;
        }

        .filter-section .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-section .filter-group label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin: 0;
            white-space: nowrap;
        }

        .filter-section .filter-group .form-control,
        .filter-section .filter-group .form-select {
            border-radius: 8px;
            padding: 6px 12px;
            border: 1.5px solid #e9ecef;
            font-size: 13px;
            height: 36px;
            min-width: 150px;
            background: #fff;
        }

        .filter-section .filter-group .form-control:focus,
        .filter-section .filter-group .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .filter-section .filter-group.search-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-section .filter-group.search-group .form-control {
            width: 100%;
            min-width: 180px;
        }

        .filter-section .filter-actions {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-left: auto;
        }

        .filter-section .filter-actions .btn {
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 13px;
            height: 36px;
        }

        .card-custom {
            background: #fff;
            border: none;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.03);
            overflow: hidden;
            width: 100%;
        }

        .card-custom .card-header-custom {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
            background: #f8f9fa;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 13px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 0;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }

        .table {
            margin-bottom: 0;
            width: 100%;
            min-width: 700px;
        }

        .table th {
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #6c757d;
            border-bottom: 2px solid #f0f2f5;
            padding: 10px 12px;
            white-space: nowrap;
            background: #f8f9fa;
        }

        .table td {
            vertical-align: middle;
            padding: 10px 12px;
            font-size: 12px;
            border-bottom: 1px solid #f0f2f5;
        }

        .user-avatar-sm {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            color: #fff;
            flex-shrink: 0;
        }

        .status-badge {
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .role-badge {
            background: #e7f3ff;
            color: #0d6efd;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
            display: inline-block;
            margin: 1px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 1.5px solid #e9ecef;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .btn-outline-primary {
            background: transparent;
            border: 1.5px solid #4facfe;
            color: #4facfe;
        }

        .btn-outline-primary:hover {
            background: #4facfe;
            color: #fff;
        }

        .btn-outline-danger {
            background: transparent;
            border: 1.5px solid #dc3545;
            color: #dc3545;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state .icon {
            font-size: 48px;
            color: #adb5bd;
            margin-bottom: 10px;
        }

        .empty-state h5 {
            color: #6c757d;
            margin-bottom: 5px;
        }

        .empty-state p {
            color: #adb5bd;
            font-size: 13px;
        }

        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 8px;
            left: 8px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 18px;
            cursor: pointer;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 60px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .sidebar-header h4 i {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: none;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 16px;
            }

            .sidebar .nav-link {
                text-align: center;
                padding: 10px;
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
                margin-left: 60px;
                width: calc(100% - 60px);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
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
                font-size: 18px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 10px;
                font-size: 14px;
            }

            .sidebar .nav-link {
                text-align: left;
                padding: 8px 14px;
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
            }

            .topbar {
                padding: 8px 14px;
            }

            .topbar h4 {
                font-size: 16px;
            }

            .content-area {
                padding: 10px 12px;
            }

            .sidebar-toggle {
                display: block !important;
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .filter-section .filter-group.search-group {
                min-width: unset;
            }

            .filter-section .filter-group {
                width: 100%;
            }

            .filter-section .filter-group .form-control,
            .filter-section .filter-group .form-select {
                width: 100%;
                min-width: unset;
            }

            .filter-section .filter-actions {
                margin-left: 0;
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
        }

        @media (max-width: 480px) {
            .topbar {
                flex-direction: column;
                align-items: stretch;
                gap: 6px;
            }

            .topbar .topbar-actions {
                justify-content: flex-start;
            }

            .topbar .topbar-actions .btn {
                font-size: 11px;
                padding: 4px 10px;
            }

            .content-area {
                padding: 8px 8px;
            }

            .page-header h2 {
                font-size: 18px;
            }

            .table th,
            .table td {
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
                <div class="topbar">
                    <h4><i class="fas fa-users me-2"></i>Users</h4>
                    <div class="topbar-actions">
                        <span class="badge bg-danger text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/users/create.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i> Add User
                        </a>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>
                        </button>
                    </div>
                </div>

                <div class="content-area">
                    <div class="page-header">
                        <h2><i class="fas fa-users me-2 text-primary"></i>User Management</h2>
                        <span class="text-muted small"><?php echo count($users); ?> user(s) found</span>
                    </div>

                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success'];
                                                                        unset($_SESSION['success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $_SESSION['error'];
                                                                            unset($_SESSION['error']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Filter Section -->
                    <div class="filter-section">
                        <div class="filter-group search-group">
                            <label for="searchInput"><i class="fas fa-search"></i></label>
                            <input type="text" class="form-control" id="searchInput" name="search"
                                placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                        </div>
                        <div class="filter-group">
                            <label for="tenantFilter">Tenant</label>
                            <select class="form-select" id="tenantFilter" name="tenant_id">
                                <option value="">All Tenants</option>
                                <?php foreach ($tenants as $tenant): ?>
                                    <option value="<?php echo $tenant['id']; ?>" <?php echo $tenantFilter == $tenant['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="statusFilter">Status</label>
                            <select class="form-select" id="statusFilter" name="status">
                                <option value="">All</option>
                                <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="button" class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                        </div>
                    </div>

                    <!-- Users List -->
                    <div class="card-custom">
                        <div class="card-body-custom">
                            <div class="table-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Tenant</th>
                                            <th>Roles</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($users)): ?>
                                            <tr>
                                                <td colspan="8">
                                                    <div class="empty-state">
                                                        <div class="icon"><i class="fas fa-users"></i></div>
                                                        <h5>No users found</h5>
                                                        <p>Create your first user to get started.</p>
                                                        <a href="/platform/users/create.php" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-plus me-1"></i> Create User
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($users as $user): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <div class="user-avatar-sm">
                                                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                                                            </div>
                                                            <div>
                                                                <a href="/platform/users/view.php?id=<?php echo $user['id']; ?>" class="fw-semibold text-decoration-none text-dark">
                                                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><code><?php echo htmlspecialchars($user['username']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                    <td><?php echo htmlspecialchars($user['tenant_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php
                                                        $roleNames = explode(', ', $user['role_names'] ?? '');
                                                        foreach ($roleNames as $role):
                                                            if (!empty($role)):
                                                        ?>
                                                                <span class="role-badge"><?php echo htmlspecialchars($role); ?></span>
                                                        <?php
                                                            endif;
                                                        endforeach;
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                                    <td class="text-end">
                                                        <a href="/platform/users/view.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-primary btn-sm" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="/platform/users/edit.php?id=<?php echo $user['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                            <button class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo addslashes($user['first_name'] . ' ' . $user['last_name']); ?>')" title="Delete">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteName"></strong>?</p>
                    <p class="text-muted small"><i class="fas fa-info-circle me-1"></i> This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="/platform/users/delete.php" id="deleteForm">
                        <input type="hidden" name="id" id="deleteId">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // toggleSidebar() is provided by the partial at
        // app/views/partials/platform-sidebar.php.

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (!sidebar || !toggle) return;
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        window.addEventListener('resize', function() {
            const sb = document.getElementById('sidebar');
            if (sb && window.innerWidth > 768) {
                sb.classList.remove('open');
            }
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }

        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const tenant = document.getElementById('tenantFilter').value;
            const status = document.getElementById('statusFilter').value;
            let url = '/platform/users/index.php?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (tenant) url += 'tenant_id=' + tenant + '&';
            if (status) url += 'status=' + status;
            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = '/platform/users/index.php';
        }

        document.getElementById('searchInput').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });

        let deleteModal = null;

        function confirmDelete(id, name) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteName').textContent = name;
            if (!deleteModal) {
                deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            }
            deleteModal.show();
        }
    </script>
</body>

</html>