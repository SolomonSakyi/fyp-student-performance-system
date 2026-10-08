<?php

/**
 * Tenant Campuses - View campuses in the tenant
 * 
 * @package EduTrack
 * @subpackage Platform\Tenant\Campuses
 * @version 1.0
 * @filepath public/platform/tenant/campuses/index.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Sixth file of the tenant-surface sweep. Three changes:
 *     - The inline <nav class="sidebar"> block is removed and
 *       replaced by an include of app/views/partials/sidebar.php.
 *       This file's inline sidebar carried eight items (it omitted
 *       Subscription); the partial carries all nine. After the
 *       refactor, this page renders the Subscription item as well.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Tenant' to 'Student 360 Tenant'.
 *     - The @version tag was unified to 1.0.
 *   The v2.1 change (ITEM-10: Settings link on each campus card)
 *   is preserved. Every other line of the file is byte-identical
 *   to the previous version (2.1). The @package tag remains
 *   'EduTrack'.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/campuses/index.php');
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? 0;
if (!$tenantId) {
    header('Location: /platform/tenant/login.php?error=no_tenant');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Campuses - Student 360 Tenant';
$currentPage = 'campuses';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 4);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// GET FILTERS
// =============================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$schoolFilter = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

// =============================================
// GET CAMPUSES FOR THIS TENANT
// =============================================
$sql = "SELECT c.*, s.school_name 
        FROM campuses c
        JOIN schools s ON c.school_id = s.id
        WHERE c.tenant_id = ? AND c.deleted_at IS NULL";
$params = [$tenantId];

if (!empty($search)) {
    $sql .= " AND (c.campus_name LIKE ? OR c.campus_code LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if ($schoolFilter > 0) {
    $sql .= " AND c.school_id = ?";
    $params[] = $schoolFilter;
}

$sql .= " ORDER BY c.campus_name ASC";
$campuses = $db->fetchAll($sql, $params);

// =============================================
// GET SCHOOLS FOR FILTER
// =============================================
$schools = $db->fetchAll(
    "SELECT id, school_name FROM schools 
     WHERE tenant_id = ? AND deleted_at IS NULL 
     ORDER BY school_name ASC",
    [$tenantId]
);

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'User';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$tenantName = $_SESSION['tenant_name'] ?? 'My Organization';
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
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
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

        .filter-section {
            background: #fff;
            border-radius: 14px;
            padding: 12px 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
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
            border: 2px solid #e9ecef;
            font-size: 13px;
            height: 38px;
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
            height: 38px;
        }

        .filter-section .filter-actions .btn i {
            margin-right: 4px;
        }

        .campus-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .campus-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px 24px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
        }

        .campus-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
            border-color: #4facfe;
        }

        .campus-card .campus-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #fff;
            margin-bottom: 12px;
        }

        .campus-card .campus-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .campus-card .campus-code {
            font-size: 12px;
            color: #6c757d;
        }

        .campus-card .campus-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
            font-size: 13px;
            color: #6c757d;
        }

        .campus-card .campus-meta i {
            width: 16px;
            margin-right: 4px;
        }

        .badge-status {
            padding: 4px 14px;
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
            background: #e9ecef;
            color: #6c757d;
        }

        @media (max-width: 1200px) {
            .campus-grid {
                grid-template-columns: 1fr 1fr;
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

            .campus-grid {
                grid-template-columns: 1fr;
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

            .campus-card {
                padding: 14px 16px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-map-marker-alt me-2"></i>Campuses</h1>
                        <p>Manage campuses across your schools</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/campuses/create.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Campus
                        </a>
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
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

                <div class="filter-section">
                    <div class="filter-group search-group">
                        <label for="searchInput"><i class="fas fa-search"></i></label>
                        <input type="text" class="form-control" id="searchInput" name="search" placeholder="Search campuses..." value="<?php echo htmlspecialchars($search ?? ''); ?>" autocomplete="off">
                    </div>
                    <div class="filter-group">
                        <label for="schoolFilter">School</label>
                        <select class="form-select" id="schoolFilter" name="school_id">
                            <option value="0">All Schools</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?php echo $school['id']; ?>" <?php echo ($schoolFilter == $school['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($school['school_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="button" class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                    </div>
                </div>

                <div class="campus-grid">
                    <?php if (empty($campuses)): ?>
                        <div style="padding:60px 40px;text-align:center;grid-column:1/-1;background:#fff;border-radius:14px;border:1px solid rgba(0,0,0,0.03);box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <i class="fas fa-map-marker-alt" style="font-size:64px;color:#dee2e6;margin-bottom:20px;display:block;"></i>
                            <h4 style="color:#1a1a2e;margin-bottom:8px;">No Campuses Found</h4>
                            <p style="color:#6c757d;font-size:14px;max-width:400px;margin:0 auto 20px;">No campuses have been created yet.</p>
                            <a href="/platform/tenant/campuses/create.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i> Add Campus</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($campuses as $campus): ?>
                            <div class="campus-card">
                                <div class="campus-icon"><i class="fas fa-map-marker-alt"></i></div>
                                <div class="campus-name"><?php echo htmlspecialchars($campus['campus_name']); ?></div>
                                <div class="campus-code">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($campus['campus_code'] ?? 'N/A'); ?>
                                    <span class="badge-status <?php echo $campus['status'] ?? 'active'; ?>"><?php echo ucfirst($campus['status'] ?? 'Active'); ?></span>
                                </div>
                                <div class="campus-meta">
                                    <span><i class="fas fa-school"></i> <?php echo htmlspecialchars($campus['school_name']); ?></span>
                                    <?php if (!empty($campus['city'])): ?>
                                        <span><i class="fas fa-city"></i> <?php echo htmlspecialchars($campus['city']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="margin-top:12px;padding-top:12px;border-top:1px solid #f0f2f5;display:flex;gap:6px;">
                                    <a href="/platform/tenant/campuses/settings.php?campus_id=<?php echo $campus['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Settings"><i class="fas fa-cog"></i></a>
                                    <a href="/platform/tenant/campuses/edit.php?id=<?php echo $campus['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                    <button class="btn btn-outline-danger btn-sm" title="Delete" onclick="deleteCampus(<?php echo $campus['id']; ?>, '<?php echo htmlspecialchars($campus['campus_name']); ?>')"><i class="fas fa-trash"></i></button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('open');
            }
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
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

        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const schoolId = document.getElementById('schoolFilter').value;
            let url = '/platform/tenant/campuses/index.php?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (schoolId && schoolId != 0) url += 'school_id=' + schoolId;
            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = '/platform/tenant/campuses/index.php';
        }

        function deleteCampus(id, name) {
            if (confirm('Are you sure you want to delete campus: "' + name + '"?\n\nThis action cannot be undone!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/platform/tenant/campuses/delete.php';
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'campus_id';
                idInput.value = id;
                form.appendChild(idInput);
                const confirmInput = document.createElement('input');
                confirmInput.type = 'hidden';
                confirmInput.name = 'confirm';
                confirmInput.value = 'yes';
                form.appendChild(confirmInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        applyFilters();
                    }
                });
            }
            document.getElementById('schoolFilter').addEventListener('change', function() {
                applyFilters();
            });
        });
    </script>
</body>

</html>