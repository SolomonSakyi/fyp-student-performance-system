<?php

/**
 * View School - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Schools
 * @filepath public/platform/schools/view.php
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
 *   /platform/tenant/dashboard.php. The five queries (school joined
 *   to tenant, campus count, staff count, student count, recent
 *   campuses) are unchanged. The profile header, the four stat
 *   cards, the School Information card, the Contact Details card,
 *   the Recent Campuses table, the delete modal, the
 *   confirmDelete() JS, and logout() are unchanged.
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

$schoolId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($schoolId <= 0) {
    $_SESSION['error'] = 'Invalid school ID.';
    header('Location: /platform/schools/index.php');
    exit;
}

$pageTitle = 'View School - Student 360 Platform';
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

// Get school details
$school = $db->fetchOne(
    "SELECT s.*, t.tenant_name 
     FROM schools s 
     LEFT JOIN tenants t ON s.tenant_id = t.id 
     WHERE s.id = ? AND s.deleted_at IS NULL",
    [$schoolId]
);

if (!$school) {
    $_SESSION['error'] = 'School not found.';
    header('Location: /platform/schools/index.php');
    exit;
}

// Get statistics
$campusCount = $db->getValue(
    "SELECT COUNT(*) FROM campuses WHERE school_id = ? AND deleted_at IS NULL",
    [$schoolId]
);

$staffCount = $db->getValue(
    "SELECT COUNT(*) FROM staff WHERE school_id = ? AND deleted_at IS NULL",
    [$schoolId]
);

$studentCount = $db->getValue(
    "SELECT COUNT(*) FROM students WHERE school_id = ? AND deleted_at IS NULL",
    [$schoolId]
);

// Get recent campuses
$recentCampuses = $db->fetchAll(
    "SELECT id, campus_name, campus_code, status, created_at 
     FROM campuses 
     WHERE school_id = ? AND deleted_at IS NULL 
     ORDER BY created_at DESC LIMIT 5",
    [$schoolId]
);

$statusBadge = [
    'active' => 'active',
    'inactive' => 'inactive'
];
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
            align-items: center;
            gap: 8px;
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

        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 16px 20px;
            background: #fff;
            border-radius: 10px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.03);
            flex-wrap: wrap;
        }

        .profile-header .profile-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 28px;
            color: #fff;
            flex-shrink: 0;
        }

        .profile-header .profile-info .name {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .profile-header .profile-info .code {
            font-size: 13px;
            color: #6c757d;
        }

        .profile-header .profile-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .stat-card-mini {
            background: #fff;
            border-radius: 10px;
            padding: 12px 16px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            text-align: center;
        }

        .stat-card-mini .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .stat-card-mini .stat-label {
            font-size: 12px;
            color: #6c757d;
        }

        .stat-card-mini .stat-icon {
            font-size: 20px;
            margin-bottom: 4px;
            display: block;
        }

        .detail-row {
            display: flex;
            padding: 6px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .label {
            font-weight: 500;
            color: #6c757d;
            width: 160px;
            flex-shrink: 0;
        }

        .detail-row .value {
            flex: 1;
            color: #1a1a2e;
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

        .btn-outline-danger {
            background: transparent;
            border: 1.5px solid #dc3545;
            color: #dc3545;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }

        .table {
            margin-bottom: 0;
            width: 100%;
            min-width: 400px;
        }

        .table th {
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #6c757d;
            border-bottom: 2px solid #f0f2f5;
            padding: 6px 8px;
            white-space: nowrap;
            background: #f8f9fa;
        }

        .table td {
            vertical-align: middle;
            padding: 6px 8px;
            font-size: 12px;
            border-bottom: 1px solid #f0f2f5;
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

            .profile-header {
                flex-direction: column;
                align-items: flex-start;
                text-align: center;
            }

            .profile-header .profile-actions {
                margin-left: 0;
                width: 100%;
                justify-content: center;
            }

            .detail-row {
                flex-direction: column;
            }

            .detail-row .label {
                width: 100%;
                margin-bottom: 2px;
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

            .profile-header .profile-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }

            .profile-header .profile-info .name {
                font-size: 16px;
            }

            .stat-card-mini .stat-number {
                font-size: 20px;
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
                        <h1><i class="fas fa-school me-2"></i>School Details</h1>
                        <p>View and manage school information</p>
                    </div>
                    <div class="header-actions">
                        <span class="badge bg-danger text-white d-none d-md-inline-block">
                            <i class="fas fa-crown me-1"></i> Super Admin
                        </span>
                        <a href="/platform/schools/index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back
                        </a>
                        <a href="/platform/schools/edit.php?id=<?php echo $schoolId; ?>" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit me-1"></i> Edit
                        </a>
                    </div>
                </div>

                <div class="content-area">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-icon">
                            <?php echo strtoupper(substr($school['school_name'], 0, 1)); ?>
                        </div>
                        <div class="profile-info">
                            <div class="name"><?php echo htmlspecialchars($school['school_name']); ?></div>
                            <div class="code">
                                <i class="fas fa-tag me-1"></i> <?php echo htmlspecialchars($school['school_code']); ?>
                                <span class="status-badge <?php echo $statusBadge[$school['status']] ?? 'inactive'; ?>">
                                    <?php echo ucfirst($school['status']); ?>
                                </span>
                            </div>
                            <div class="code">
                                <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($school['tenant_name'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="profile-actions">
                            <a href="/platform/campuses/index.php?school_id=<?php echo $schoolId; ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-map-marker-alt me-1"></i> Campuses
                            </a>
                            <button class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $schoolId; ?>, '<?php echo addslashes($school['school_name']); ?>')">
                                <i class="fas fa-trash me-1"></i> Delete
                            </button>
                        </div>
                    </div>

                    <!-- Stats Row -->
                    <div class="row g-2 g-md-3 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="stat-card-mini">
                                <span class="stat-icon text-success"><i class="fas fa-map-marker-alt"></i></span>
                                <div class="stat-number"><?php echo $campusCount; ?></div>
                                <div class="stat-label">Campuses</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card-mini">
                                <span class="stat-icon text-warning"><i class="fas fa-user-tie"></i></span>
                                <div class="stat-number"><?php echo $staffCount; ?></div>
                                <div class="stat-label">Staff</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card-mini">
                                <span class="stat-icon text-danger"><i class="fas fa-user-graduate"></i></span>
                                <div class="stat-number"><?php echo $studentCount; ?></div>
                                <div class="stat-label">Students</div>
                            </div>
                        </div>
                    </div>

                    <!-- School Details -->
                    <div class="row g-2 g-md-3">
                        <div class="col-md-6">
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-info-circle me-1 text-primary"></i> School Information</h6>
                                </div>
                                <div class="card-body-custom">
                                    <div class="detail-row">
                                        <span class="label">School Name</span>
                                        <span class="value"><?php echo htmlspecialchars($school['school_name']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">School Code</span>
                                        <span class="value"><?php echo htmlspecialchars($school['school_code']); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Tenant</span>
                                        <span class="value"><?php echo htmlspecialchars($school['tenant_name'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Status</span>
                                        <span class="value">
                                            <span class="status-badge <?php echo $statusBadge[$school['status']] ?? 'inactive'; ?>">
                                                <?php echo ucfirst($school['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Created</span>
                                        <span class="value"><?php echo date('F d, Y h:i A', strtotime($school['created_at'])); ?></span>
                                    </div>
                                    <?php if ($school['updated_at'] && $school['updated_at'] != $school['created_at']): ?>
                                        <div class="detail-row">
                                            <span class="label">Last Updated</span>
                                            <span class="value"><?php echo date('F d, Y h:i A', strtotime($school['updated_at'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-address-card me-1 text-primary"></i> Contact Details</h6>
                                </div>
                                <div class="card-body-custom">
                                    <?php if (!empty($school['email'])): ?>
                                        <div class="detail-row">
                                            <span class="label">Email</span>
                                            <span class="value">
                                                <a href="mailto:<?php echo htmlspecialchars($school['email']); ?>">
                                                    <?php echo htmlspecialchars($school['email']); ?>
                                                </a>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($school['phone'])): ?>
                                        <div class="detail-row">
                                            <span class="label">Phone</span>
                                            <span class="value">
                                                <a href="tel:<?php echo htmlspecialchars($school['phone']); ?>">
                                                    <?php echo htmlspecialchars($school['phone']); ?>
                                                </a>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($school['address'])): ?>
                                        <div class="detail-row">
                                            <span class="label">Address</span>
                                            <span class="value"><?php echo htmlspecialchars($school['address']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Campuses -->
                    <div class="row g-2 g-md-3 mt-2">
                        <div class="col-12">
                            <div class="card-custom">
                                <div class="card-header-custom">
                                    <h6><i class="fas fa-map-marker-alt me-1 text-primary"></i> Recent Campuses</h6>
                                    <a href="/platform/campuses/index.php?school_id=<?php echo $schoolId; ?>" class="text-primary small">
                                        View All <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                                <div class="card-body-custom p-0">
                                    <div class="table-container">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Campus Name</th>
                                                    <th>Code</th>
                                                    <th>Status</th>
                                                    <th>Created</th>
                                                    <th class="text-end">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($recentCampuses)): ?>
                                                    <tr>
                                                        <td colspan="5" class="text-center text-muted py-3">
                                                            <i class="fas fa-map-marker-alt me-1"></i> No campuses found for this school
                                                        </td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($recentCampuses as $campus): ?>
                                                        <tr>
                                                            <td><?php echo htmlspecialchars($campus['campus_name']); ?></td>
                                                            <td><code><?php echo htmlspecialchars($campus['campus_code']); ?></code></td>
                                                            <td>
                                                                <span class="status-badge <?php echo $campus['status']; ?>">
                                                                    <?php echo ucfirst($campus['status']); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo date('M d, Y', strtotime($campus['created_at'])); ?></td>
                                                            <td class="text-end">
                                                                <a href="/platform/campuses/view.php?id=<?php echo $campus['id']; ?>" class="btn btn-outline-primary btn-sm">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
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