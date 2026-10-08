<?php

/**
 * View School - Tenant Admin views school details (Read-Only)
 * 
 * @package EduTrack
 * @subpackage Platform\Tenant\Schools
 * @version 1.0
 * @filepath public/platform/tenant/schools/view.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Third file of the tenant-surface sweep. Three changes:
 *     - The inline <nav class="sidebar"> block is removed and
 *       replaced by an include of app/views/partials/sidebar.php.
 *       The partial renders the uniform nine-item sidebar. This
 *       file's inline sidebar carried eight items (it omitted
 *       Subscription); the partial carries all nine. After the
 *       refactor, this page renders the Subscription item as well.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Tenant' to 'Student 360 Tenant'.
 *     - The @version tag was unified to 1.0.
 *   Every other line of the file is byte-identical to the previous
 *   version (2.0). The @package tag remains 'EduTrack' — it names
 *   the codebase package, not the product.
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

// Check if user is Super Admin - redirect to platform
if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
    header('Location: /platform/schools/view.php?id=' . ($_GET['id'] ?? 0));
    exit;
}

// Get tenant ID from session
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$tenantId || !$schoolId) {
    header('Location: /platform/tenant/schools/index.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'School Details - Student 360 Tenant';
$currentPage = 'schools';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// =============================================
// FIND PROJECT ROOT - MULTIPLE METHODS
// =============================================

// Method 1: Try relative path from current file
// Current file: public/platform/tenant/schools/view.php
$projectRoot = dirname(__DIR__, 4); // Goes up to project root

// Method 2: Check if config exists at that path
if (!file_exists($projectRoot . '/config/config.php')) {
    // Try using document root
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    // Document root should be the 'public' folder
    $projectRoot = dirname($docRoot);
}

// Method 3: Try hardcoded path
if (!file_exists($projectRoot . '/config/config.php')) {
    $projectRoot = 'C:/Users/Almighty/Documents/FYP_Student_Performance_System_0.1';
}

// Method 4: Try going up from __DIR__ until we find config
if (!file_exists($projectRoot . '/config/config.php')) {
    $testPath = __DIR__;
    for ($i = 0; $i < 10; $i++) {
        if (file_exists($testPath . '/config/config.php')) {
            $projectRoot = $testPath;
            break;
        }
        $testPath = dirname($testPath);
    }
}

// =============================================
// LOAD CONFIG
// =============================================
$configPath = $projectRoot . '/config/config.php';

if (!file_exists($configPath)) {
    // Debug information
    echo "<h1>Config file not found</h1>";
    echo "<p>Tried path: " . htmlspecialchars($configPath) . "</p>";
    echo "<p>__DIR__: " . htmlspecialchars(__DIR__) . "</p>";
    echo "<p>Document Root: " . htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'Not set') . "</p>";
    echo "<p>Project Root: " . htmlspecialchars($projectRoot) . "</p>";
    echo "<p>Please check the file path.</p>";
    exit;
}

require_once $configPath;

// =============================================
// LOAD DATABASE HELPER
// =============================================
$helperPath = $projectRoot . '/app/helpers/DatabaseHelper.php';

if (!file_exists($helperPath)) {
    die('DatabaseHelper.php not found at: ' . $helperPath);
}

require_once $helperPath;

try {
    $db = DatabaseHelper::getInstance();
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// =============================================
// GET SCHOOL DETAILS (Tenant-scoped)
// =============================================
$school = $db->fetchOne(
    "SELECT s.*, 
            (SELECT COUNT(*) FROM campuses c WHERE c.school_id = s.id AND c.deleted_at IS NULL) as campus_count,
            (SELECT COUNT(*) FROM staff WHERE school_id = s.id AND deleted_at IS NULL) as staff_count,
            (SELECT COUNT(*) FROM students WHERE school_id = s.id AND deleted_at IS NULL) as student_count
     FROM schools s 
     WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
    [$schoolId, $tenantId]
);

if (!$school) {
    header('Location: /platform/tenant/schools/index.php?error=school_not_found');
    exit;
}

// =============================================
// GET CAMPUSES UNDER THIS SCHOOL
// =============================================
$campuses = $db->fetchAll(
    "SELECT * FROM campuses 
     WHERE school_id = ? AND deleted_at IS NULL 
     ORDER BY campus_name ASC",
    [$schoolId]
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
        /* SCHOOL HEADER */
        /* ================================================ */
        .school-header {
            background: #fff;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 24px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .school-header .school-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .school-header .school-info .school-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: #fff;
            flex-shrink: 0;
        }

        .school-header .school-info .school-name {
            font-weight: 700;
            font-size: 22px;
            color: #1a1a2e;
        }

        .school-header .school-info .school-code {
            font-size: 13px;
            color: #6c757d;
        }

        .school-header .school-info .school-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 4px;
            font-size: 13px;
            color: #6c757d;
        }

        .school-header .school-info .school-meta i {
            width: 16px;
            margin-right: 4px;
        }

        .badge-status {
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.active {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.suspended {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.inactive {
            background: #e9ecef;
            color: #6c757d;
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
            text-align: center;
        }

        .stat-card .stat-icon {
            font-size: 20px;
            color: #4facfe;
            margin-bottom: 4px;
        }

        .stat-card .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
            margin-top: 2px;
        }

        /* ================================================ */
        /* CAMPUS LIST */
        /* ================================================ */
        .campus-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            transition: all 0.3s;
        }

        .campus-item:hover {
            background: #fff;
            border-color: #4facfe;
        }

        .campus-item .campus-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .campus-item .campus-code {
            font-size: 12px;
            color: #6c757d;
        }

        .campus-item .campus-meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: #6c757d;
            flex-wrap: wrap;
        }

        .campus-item .campus-meta i {
            margin-right: 4px;
        }

        /* ================================================ */
        /* RESPONSIVE */
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

            .school-header {
                flex-direction: column;
                align-items: stretch;
            }

            .school-header .school-info {
                flex-wrap: wrap;
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }

            .campus-item {
                flex-direction: column;
                align-items: stretch;
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

            .stats-row {
                grid-template-columns: 1fr;
            }

            .school-header {
                padding: 16px;
            }

            .school-header .school-info .school-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }

            .school-header .school-info .school-name {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Sidebar Toggle -->
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-school me-2"></i>School Details</h1>
                        <p>View school information</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/schools/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Schools
                        </a>
                    </div>
                </div>

                <!-- School Header -->
                <div class="school-header">
                    <div class="school-info">
                        <div class="school-icon"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="school-name">
                                <?php echo htmlspecialchars($school['school_name']); ?>
                            </div>
                            <div class="school-code">
                                Code: <?php echo htmlspecialchars($school['school_code'] ?? 'N/A'); ?>
                                <span class="badge-status <?php echo $school['status'] ?? 'active'; ?>">
                                    <?php echo ucfirst($school['status'] ?? 'Active'); ?>
                                </span>
                            </div>
                            <div class="school-meta">
                                <?php if (!empty($school['email'])): ?>
                                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($school['email']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($school['phone'])): ?>
                                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($school['phone']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($school['city'])): ?>
                                    <span><i class="fas fa-city"></i> <?php echo htmlspecialchars($school['city']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-secondary">ID: <?php echo $schoolId; ?></span>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="stat-number"><?php echo $school['campus_count'] ?? 0; ?></div>
                        <div class="stat-label">Campuses</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                        <div class="stat-number"><?php echo $school['staff_count'] ?? 0; ?></div>
                        <div class="stat-label">Staff</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        <div class="stat-number"><?php echo $school['student_count'] ?? 0; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-calendar"></i></div>
                        <div class="stat-number"><?php echo date('M d, Y', strtotime($school['created_at'] ?? 'now')); ?></div>
                        <div class="stat-label">Created</div>
                    </div>
                </div>

                <!-- School Details -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-info-circle me-2 text-primary"></i>School Information</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <div class="col-md-6 col-12">
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td style="width:120px;font-weight:500;">School Name</td>
                                        <td><?php echo htmlspecialchars($school['school_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td style="font-weight:500;">School Code</td>
                                        <td><?php echo htmlspecialchars($school['school_code'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td style="font-weight:500;">Type</td>
                                        <td><?php echo ucfirst($school['school_type'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td style="font-weight:500;">Status</td>
                                        <td><span class="badge-status <?php echo $school['status'] ?? 'active'; ?>"><?php echo ucfirst($school['status'] ?? 'Active'); ?></span></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6 col-12">
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td style="width:120px;font-weight:500;">Email</td>
                                        <td><?php echo htmlspecialchars($school['email'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td style="font-weight:500;">Phone</td>
                                        <td><?php echo htmlspecialchars($school['phone'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td style="font-weight:500;">City</td>
                                        <td><?php echo htmlspecialchars($school['city'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <tr>
                                        <td style="font-weight:500;">Address</td>
                                        <td><?php echo htmlspecialchars($school['address'] ?? 'N/A'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <?php if (!empty($school['postal_address'])): ?>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <p><strong>Postal Address:</strong> <?php echo htmlspecialchars($school['postal_address']); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($school['region'])): ?>
                            <div class="row">
                                <div class="col-12">
                                    <p><strong>Region:</strong> <?php echo htmlspecialchars($school['region']); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Campuses -->
                <?php if (!empty($campuses)): ?>
                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-map-marker-alt me-2 text-primary"></i>Campuses (<?php echo count($campuses); ?>)</h6>
                        </div>
                        <div class="card-body-custom">
                            <?php foreach ($campuses as $campus): ?>
                                <div class="campus-item">
                                    <div>
                                        <div class="campus-name">
                                            <?php echo htmlspecialchars($campus['campus_name']); ?>
                                            <span class="badge-status <?php echo $campus['status'] ?? 'active'; ?>" style="font-size:10px;padding:2px 10px;">
                                                <?php echo ucfirst($campus['status'] ?? 'Active'); ?>
                                            </span>
                                        </div>
                                        <div class="campus-code">
                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($campus['campus_code'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="campus-meta">
                                        <?php if (!empty($campus['city'])): ?>
                                            <span><i class="fas fa-city"></i> <?php echo htmlspecialchars($campus['city']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($campus['email'])): ?>
                                            <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($campus['email']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($campus['phone'])): ?>
                                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($campus['phone']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // SIDEBAR TOGGLE
        // ================================================
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

        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
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
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
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