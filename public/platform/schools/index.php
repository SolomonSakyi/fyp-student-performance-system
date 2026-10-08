<?php

/**
 * Schools Management - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Schools
 * @filepath public/platform/schools/index.php
 * @version 2.1
 *
 * v2.1 change (2026-10-08) [SWEEP X-1 + SIDEBAR + CSRF]:
 *   Platform-schools sweep, X-1 in full, plus sidebar reconciliation,
 *   plus the CSRF field on the delete modal form.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 7 items across
 *   4 groups (Main: Dashboard; Management: Tenants, Schools,
 *   Campuses; Users: Users; System: Audit, Settings), brand heading
 *   "EduTrack", subtitle "Super Admin", width 230px — is replaced
 *   by a single require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System),
 *   brand heading "Student 360", subtitle "Platform Administration".
 *
 *   The page's .sidebar and .main-content width rules are changed
 *   from 230px to 260px, and the 992px breakpoint from 60px to
 *   72px, so the page's CSS matches the shape of every other swept
 *   platform-root page. The page's top-bar markup was realigned from
 *   the local .topbar / .topbar-actions classes to the canonical
 *   .top-bar / .page-title / .header-actions classes, and the CSS
 *   block updated to match, so the page renders consistently with
 *   the other swept platform-root pages.
 *
 *   $currentPage stays 'schools' so the Schools item in the
 *   canonical partial is marked active. $pendingApprovals = 0 is
 *   set before the partial. $userFirstName is set and $userAvatar
 *   is derived from it, matching the other swept pages.
 *
 *   CSRF field. The delete modal form in this page now carries a
 *   hidden csrf_token input, sourced from $csrfToken, which is
 *   populated server-side from csrf_token() or Security::csrfToken().
 *   The handler at public/platform/schools/delete.php will need its
 *   own verify_csrf() call to accept the token this form now emits.
 *
 *   The page's auth guards are unchanged: logged_in redirects to
 *   /platform/tenant/login.php, is_super_admin redirects to
 *   /platform/tenant/dashboard.php. The two queries (tenants,
 *   schools joined to tenants) and the per-school campus-count loop
 *   are unchanged. The filter form, the table, the delete modal,
 *   the confirmDelete() JS, the applyFilters() / resetFilters() JS,
 *   and logout() are unchanged.
 *
 *   NOTE on record: schools/select.php exists in the folder listing;
 *   schools/delete.php is the target of the delete modal form.
 *   schools/settings/ carries two files with a stale API_BASE
 *   constant that this page does not reach.
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

$pageTitle = 'Schools - Student 360 Platform';
$currentPage = 'schools';

$userName = $_SESSION['user_name'] ?? 'Super Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$userInitial = $userAvatar;

// Variables read by the platform sidebar partial
$currentUser = $userName;
$pendingApprovals = 0;

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

// CSRF token for the delete modal form
$csrfToken = '';
if (function_exists('csrf_token')) {
    $csrfToken = csrf_token();
} elseif (class_exists('Security') && method_exists('Security', 'csrfToken')) {
    $csrfToken = Security::csrfToken();
}

// Get filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$tenantFilter = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

// Get all tenants for filter dropdown
$tenants = $db->fetchAll("SELECT id, tenant_name FROM tenants WHERE deleted_at IS NULL ORDER BY tenant_name");

// Build query
$sql = "SELECT s.*, t.tenant_name 
        FROM schools s 
        LEFT JOIN tenants t ON s.tenant_id = t.id 
        WHERE s.deleted_at IS NULL";
$params = [];

if (!empty($search)) {
    $sql .= " AND (s.school_name LIKE ? OR s.school_code LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if ($tenantFilter > 0) {
    $sql .= " AND s.tenant_id = ?";
    $params[] = $tenantFilter;
}
if (!empty($statusFilter)) {
    $sql .= " AND s.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY s.school_name ASC";
$schools = $db->fetchAll($sql, $params);

// Get campus count for each school
foreach ($schools as &$school) {
    $school['campus_count'] = $db->getValue(
        "SELECT COUNT(*) FROM campuses WHERE school_id = ? AND deleted_at IS NULL",
        [$school['id']]
    ) ?? 0;
}
unset($school);

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
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
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
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
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
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 11px;
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
            margin-left: 260px;
            min-height: 100vh;
            width: calc(100% - 260px);
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid #e9ecef;
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 999;
            flex-wrap: wrap;
            gap: 8px;
        }

        .top-bar .page-title h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
        }

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 13px;
        }

        .top-bar .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .top-bar .header-actions .btn {
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
            min-width: 600px;
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
                width: 100%;
            }

            .top-bar {
                padding: 8px 14px;
            }

            .top-bar .page-title h1 {
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
            .top-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 6px;
            }

            .top-bar .header-actions {
                justify-content: flex-start;
            }

            .top-bar .header-actions .btn {
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
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-school me-2"></i>Schools</h1>
                        <p>Manage all schools on the platform</p>
                    </div>
                    <div class="header-actions">
                        <span class="badge bg-danger text-white d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/schools/create.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i> Add School
                        </a>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>
                        </button>
                    </div>
                </div>

                <div class="content-area">
                    <div class="page-header">
                        <h2><i class="fas fa-school me-2 text-primary"></i>School Management</h2>
                        <span class="text-muted small"><?php echo count($schools); ?> school(s) found</span>
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
                                placeholder="Search schools..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
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
                                <?php foreach ($statuses as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $statusFilter == $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="button" class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                        </div>
                    </div>

                    <!-- Schools List -->
                    <div class="card-custom">
                        <div class="card-body-custom">
                            <div class="table-container">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>School Name</th>
                                            <th>Code</th>
                                            <th>Tenant</th>
                                            <th>Campuses</th>
                                            <th>Status</th>
                                            <th>Created</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($schools)): ?>
                                            <tr>
                                                <td colspan="7">
                                                    <div class="empty-state">
                                                        <div class="icon"><i class="fas fa-school"></i></div>
                                                        <h5>No schools found</h5>
                                                        <p>Create your first school to get started.</p>
                                                        <a href="/platform/schools/create.php" class="btn btn-primary btn-sm">
                                                            <i class="fas fa-plus me-1"></i> Create School
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($schools as $school): ?>
                                                <tr>
                                                    <td>
                                                        <a href="/platform/schools/view.php?id=<?php echo $school['id']; ?>">
                                                            <?php echo htmlspecialchars($school['school_name']); ?>
                                                        </a>
                                                    </td>
                                                    <td><code><?php echo htmlspecialchars($school['school_code']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($school['tenant_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo $school['campus_count']; ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo $school['status']; ?>">
                                                            <?php echo ucfirst($school['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M d, Y', strtotime($school['created_at'])); ?></td>
                                                    <td class="text-end">
                                                        <a href="/platform/schools/view.php?id=<?php echo $school['id']; ?>" class="btn btn-outline-primary btn-sm" title="View">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="/platform/schools/edit.php?id=<?php echo $school['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $school['id']; ?>, '<?php echo addslashes($school['school_name']); ?>')" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
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
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Delete School</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteName"></strong>?</p>
                    <p class="text-muted small"><i class="fas fa-info-circle me-1"></i> This will also delete all associated campuses.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="/platform/schools/delete.php" id="deleteForm">
                        <input type="hidden" name="id" id="deleteId">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }

        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const tenant = document.getElementById('tenantFilter').value;
            const status = document.getElementById('statusFilter').value;
            let url = '/platform/schools/index.php?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (tenant) url += 'tenant_id=' + tenant + '&';
            if (status) url += 'status=' + status;
            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = '/platform/schools/index.php';
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