<?php

/**
 * Super Admin Dashboard - Main landing page for Super Admin
 * 
 * @package EduTrack
 * @subpackage Platform
 * @version 2.2
 * @filepath public/platform/index.php
 *
 * v2.2 change (2026-10-07) [SWEEP SIDEBAR]:
 *   Sidebar reconciliation. The inline sidebar this page carried
 *   since before v2.1 is replaced by a single require_once of the
 *   new shared partial at app/views/partials/platform-sidebar.php
 *   v1.0. That partial carries the canonical platform-root sidebar
 *   shape, matching the shape v2.1 already carried: 11 items
 *   across 4 groups (Main, Institution, Management, System),
 *   folder paths for Tenants (/platform/tenants/index.php) and
 *   Settings (/platform/settings/index.php) per the confirmed
 *   folder listings, no Register Tenant item, plus the mobile
 *   toggle, the sidebar footer, and the toggleSidebar() JS.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler and resize
 *   handler are kept — they are page-scoped and do not conflict
 *   with the partial's toggle. Every other line of the file is
 *   byte-identical to v2.1.
 *
 * v2.1 change (2026-10-07) [SWEEP X-1]:
 *   Platform-root sweep, X-1 in full. Two visible "EduTrack"
 *   strings were renamed to "Student 360":
 *     - The $pageTitle. It read
 *         'Super Admin Dashboard - EduTrack Platform'
 *       and now reads
 *         'Super Admin Dashboard - Student 360 Platform'
 *     - The sidebar brand heading. It read
 *         <h4>...EduTrack</h4>
 *       and now reads
 *         <h4>...Student 360</h4>
 *   This v2.1 [SWEEP] docblock paragraph was added above the
 *   existing description.
 *
 *   This page carried an inline sidebar that links to the
 *   platform-root navigation surface. That sidebar was preserved
 *   unchanged in v2.1: replacing it with the tenant partial would
 *   change the page's navigation from platform-root to tenant-scoped.
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 *
 *   Every other line of the file is byte-identical to v2.0.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// AUTHENTICATION CHECK - Super Admin Only
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    $returnUrl = urlencode($_SERVER['REQUEST_URI']);
    header('Location: /platform/tenant/login.php?return=' . $returnUrl);
    exit;
}

// Check if user is Super Admin
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    // Redirect tenant users to their tenant dashboard
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Super Admin Dashboard - Student 360 Platform';
$currentPage = 'dashboard';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 2);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
require_once $projectRoot . '/app/views/partials/platform-sidebar.php';
$db = DatabaseHelper::getInstance();

// =============================================
// GET STATISTICS
// =============================================

// Tenants
$totalTenants = $db->getValue("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL") ?? 0;
$activeTenants = $db->getValue("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND status = 'active'") ?? 0;
$pendingTenants = $db->getValue("SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL AND status = 'pending'") ?? 0;

// Schools
$totalSchools = $db->getValue("SELECT COUNT(*) FROM schools WHERE deleted_at IS NULL") ?? 0;
$activeSchools = $db->getValue("SELECT COUNT(*) FROM schools WHERE deleted_at IS NULL AND status = 'active'") ?? 0;

// Campuses
$totalCampuses = $db->getValue(
    "SELECT COUNT(*) FROM campuses c 
     JOIN schools s ON c.school_id = s.id 
     WHERE c.deleted_at IS NULL AND s.deleted_at IS NULL"
) ?? 0;

// Users
$totalUsers = $db->getValue("SELECT COUNT(*) FROM platform_users WHERE deleted_at IS NULL") ?? 0;
$activeUsers = $db->getValue("SELECT COUNT(*) FROM platform_users WHERE deleted_at IS NULL AND is_active = 1") ?? 0;

// Students
$totalStudents = $db->getValue("SELECT COUNT(*) FROM students WHERE deleted_at IS NULL") ?? 0;

// Staff
$totalStaff = $db->getValue(
    "SELECT COUNT(*) FROM staff s 
     JOIN persons p ON s.person_id = p.id 
     WHERE s.deleted_at IS NULL AND p.deleted_at IS NULL"
) ?? 0;

// =============================================
// GET SUBSCRIPTION STATISTICS
// =============================================
$activeSubscriptions = $db->getValue(
    "SELECT COUNT(*) FROM tenant_subscriptions WHERE status IN ('active', 'trial') AND (deleted_at IS NULL OR deleted_at = '')"
) ?? 0;

$totalPlans = $db->getValue(
    "SELECT COUNT(*) FROM subscription_plans WHERE (deleted_at IS NULL OR deleted_at = '')"
) ?? 0;

$activePlans = $db->getValue(
    "SELECT COUNT(*) FROM subscription_plans WHERE is_active = 1 AND (deleted_at IS NULL OR deleted_at = '')"
) ?? 0;

// =============================================
// GET PENDING APPROVALS
// =============================================
$pendingApprovals = 0;
$pendingSchools = 0;
$pendingCampuses = 0;

try {
    $tableExists = $db->getValue(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = ? AND table_name = 'school_requests'",
        [DB_NAME]
    );

    if ($tableExists > 0) {
        $pendingSchools = $db->getValue(
            "SELECT COUNT(*) FROM school_requests WHERE status = 'pending' AND (deleted_at IS NULL OR deleted_at = '')"
        ) ?? 0;
    }

    $campusTableExists = $db->getValue(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = ? AND table_name = 'campus_requests'",
        [DB_NAME]
    );

    if ($campusTableExists > 0) {
        $pendingCampuses = $db->getValue(
            "SELECT COUNT(*) FROM campus_requests WHERE status = 'pending' AND (deleted_at IS NULL OR deleted_at = '')"
        ) ?? 0;
    }

    $pendingApprovals = $pendingSchools + $pendingCampuses;
} catch (Exception $e) {
    $pendingApprovals = 0;
}

// =============================================
// GET RECENT ACTIVITY
// =============================================
$recentActivity = $db->fetchAll(
    "SELECT 
        'tenant_created' as type,
        tenant_name as name,
        created_at,
        'Tenant' as category
    FROM tenants 
    WHERE deleted_at IS NULL
    
    UNION ALL
    
    SELECT 
        'school_created' as type,
        school_name as name,
        created_at,
        'School' as category
    FROM schools 
    WHERE deleted_at IS NULL
    
    UNION ALL
    
    SELECT 
        'user_created' as type,
        username as name,
        created_at,
        'User' as category
    FROM platform_users 
    WHERE deleted_at IS NULL
    
    ORDER BY created_at DESC 
    LIMIT 10"
);

// =============================================
// GET RECENT TENANTS
// =============================================
$recentTenants = $db->fetchAll(
    "SELECT id, tenant_name, tenant_code, status, created_at 
     FROM tenants 
     WHERE deleted_at IS NULL 
     ORDER BY created_at DESC 
     LIMIT 5"
);

// =============================================
// GET RECENT SUBSCRIPTION ACTIVITY
// =============================================
$recentSubscriptions = $db->fetchAll(
    "SELECT ts.*, t.tenant_name, sp.plan_name, sp.plan_code
     FROM tenant_subscriptions ts
     JOIN tenants t ON ts.tenant_id = t.id
     JOIN subscription_plans sp ON ts.plan_id = sp.id
     WHERE ts.status IN ('active', 'trial') AND (ts.deleted_at IS NULL OR ts.deleted_at = '')
     ORDER BY ts.created_at DESC 
     LIMIT 5"
);

// =============================================
// GET SUBSCRIPTION REVENUE STATS
// =============================================
$totalRevenue = $db->getValue(
    "SELECT SUM(sp.price) FROM tenant_subscriptions ts
     JOIN subscription_plans sp ON ts.plan_id = sp.id
     WHERE ts.status IN ('active', 'trial') AND (ts.deleted_at IS NULL OR ts.deleted_at = '')
     AND sp.price > 0"
) ?? 0;

$monthlyRevenue = $db->getValue(
    "SELECT SUM(sp.price) FROM tenant_subscriptions ts
     JOIN subscription_plans sp ON ts.plan_id = sp.id
     WHERE ts.status IN ('active', 'trial') AND (ts.deleted_at IS NULL OR ts.deleted_at = '')
     AND sp.price > 0 AND MONTH(ts.created_at) = MONTH(NOW()) AND YEAR(ts.created_at) = YEAR(NOW())"
) ?? 0;

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));

$greeting = 'Good Morning';
$hour = date('H');
if ($hour >= 12 && $hour < 17) {
    $greeting = 'Good Afternoon';
} elseif ($hour >= 17) {
    $greeting = 'Good Evening';
}
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

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
        }

        .btn-success:hover {
            background: #218838;
            color: #fff;
        }

        .btn-warning {
            background: #ffc107;
            border: none;
            color: #1a1a2e;
        }

        .btn-warning:hover {
            background: #e0a800;
            color: #1a1a2e;
        }

        .btn-info {
            background: #17a2b8;
            border: none;
            color: #fff;
        }

        .btn-info:hover {
            background: #138496;
            color: #fff;
        }

        /* ================================================ */
        /* WELCOME SECTION */
        /* ================================================ */
        .welcome-section {
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            border-radius: 14px;
            padding: 24px 28px;
            color: #fff;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .welcome-section:after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(79, 172, 254, 0.06);
            border-radius: 50%;
        }

        .welcome-section h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
            position: relative;
            z-index: 1;
        }

        .welcome-section p {
            opacity: 0.7;
            margin-bottom: 0;
            font-size: 14px;
            position: relative;
            z-index: 1;
        }

        .welcome-section .badge-role {
            background: rgba(255, 255, 255, 0.12);
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            position: relative;
            z-index: 1;
        }

        .welcome-section .date-display {
            opacity: 0.5;
            font-size: 12px;
            position: relative;
            z-index: 1;
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
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .stat-card .stat-icon.orange {
            background: #ffe8d9;
            color: #fd7e14;
        }

        .stat-card .stat-icon.teal {
            background: #d0f0f0;
            color: #20c997;
        }

        .stat-card .stat-icon.red {
            background: #fce4ec;
            color: #dc3545;
        }

        .stat-card .stat-icon.pink {
            background: #fce4ec;
            color: #e83e8c;
        }

        .stat-card .stat-icon.gold {
            background: #fff3cd;
            color: #856404;
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

        .stat-card .stat-sub {
            font-size: 11px;
            color: #6c757d;
            opacity: 0.8;
        }

        /* ================================================ */
        /* SUBSCRIPTION CARD */
        /* ================================================ */
        .subscription-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .sub-stat-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 14px 18px;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .sub-stat-card .sub-number {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .sub-stat-card .sub-label {
            font-size: 12px;
            color: #6c757d;
        }

        .sub-stat-card .sub-icon {
            font-size: 20px;
            margin-bottom: 4px;
            display: block;
        }

        .sub-stat-card .sub-icon.revenue {
            color: #28a745;
        }

        .sub-stat-card .sub-icon.plans {
            color: #4facfe;
        }

        .sub-stat-card .sub-icon.active {
            color: #6f42c1;
        }

        /* ================================================ */
        /* CARDS */
        /* ================================================ */
        .card-custom {
            background: #fff;
            border-radius: 14px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            width: 100%;
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
            padding: 16px 24px;
        }

        /* ================================================ */
        /* ACTIVITY */
        /* ================================================ */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-item .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            flex-shrink: 0;
            font-size: 14px;
        }

        .activity-item .activity-icon.tenant {
            background: #4facfe;
        }

        .activity-item .activity-icon.school {
            background: #28a745;
        }

        .activity-item .activity-icon.user {
            background: #6f42c1;
        }

        .activity-item .activity-icon.subscription {
            background: #fd7e14;
        }

        .activity-item .activity-content {
            flex: 1;
        }

        .activity-item .activity-content .activity-name {
            font-weight: 500;
            font-size: 14px;
            color: #1a1a2e;
        }

        .activity-item .activity-content .activity-time {
            font-size: 12px;
            color: #6c757d;
        }

        .activity-item .activity-badge {
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 10px;
        }

        /* ================================================ */
        /* TENANT LIST */
        /* ================================================ */
        .tenant-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }

        .tenant-item:last-child {
            border-bottom: none;
        }

        .tenant-item .tenant-name {
            font-weight: 500;
            color: #1a1a2e;
        }

        .tenant-item .tenant-code {
            font-size: 12px;
            color: #6c757d;
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

        .badge-status.trial {
            background: #cce5ff;
            color: #004085;
        }

        /* ================================================ */
        /* RESPONSIVE */
        /* ================================================ */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .subscription-stats {
                grid-template-columns: repeat(3, 1fr);
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

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .subscription-stats {
                grid-template-columns: 1fr 1fr;
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
                grid-template-columns: 1fr 1fr;
            }

            .subscription-stats {
                grid-template-columns: 1fr;
            }

            .welcome-section {
                padding: 16px 20px;
            }

            .welcome-section h1 {
                font-size: 20px;
            }

            .card-custom .card-header-custom,
            .card-custom .card-body-custom {
                padding: 12px 16px;
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
                grid-template-columns: 1fr;
            }

            .welcome-section {
                padding: 14px 16px;
            }

            .welcome-section h1 {
                font-size: 18px;
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
                        <h1><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h1>
                        <p>Overview of the entire platform</p>
                    </div>
                    <div class="header-actions">
                        <span class="badge bg-primary text-white me-2 d-none d-md-inline-block">
                            <i class="fas fa-globe me-1"></i> Platform Admin
                        </span>
                        <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Welcome Section -->
                <div class="welcome-section">
                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div>
                            <h1><?php echo $greeting; ?> <?php echo htmlspecialchars($userFirstName); ?>!</h1>
                            <p>Welcome to the Super Admin dashboard. Manage tenants, schools, subscriptions, and system settings.</p>
                            <span class="badge-role"><i class="fas fa-crown me-1"></i> Super Administrator</span>
                        </div>
                        <div class="text-end">
                            <div class="date-display"><?php echo date('l, F d, Y'); ?></div>
                            <div class="date-display mt-1"><?php echo date('h:i A'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Stats Row -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalTenants); ?></div>
                            <div class="stat-label">Total Tenants</div>
                            <div class="stat-sub"><?php echo $activeTenants; ?> active</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalSchools); ?></div>
                            <div class="stat-label">Total Schools</div>
                            <div class="stat-sub"><?php echo $activeSchools; ?> active</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalCampuses); ?></div>
                            <div class="stat-label">Total Campuses</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalUsers); ?></div>
                            <div class="stat-label">Total Users</div>
                            <div class="stat-sub"><?php echo $activeUsers; ?> active</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon teal"><i class="fas fa-user-graduate"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalStudents); ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon pink"><i class="fas fa-user-tie"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalStaff); ?></div>
                            <div class="stat-label">Total Staff</div>
                        </div>
                    </div>
                </div>

                <!-- Subscription Stats -->
                <div class="card-custom mb-3">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-crown me-2 text-warning"></i>Subscription Overview</h6>
                        <a href="/platform/subscriptions/index.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-arrow-right me-1"></i> Manage Plans
                        </a>
                    </div>
                    <div class="card-body-custom">
                        <div class="subscription-stats">
                            <div class="sub-stat-card">
                                <span class="sub-icon revenue"><i class="fas fa-money-bill-wave"></i></span>
                                <div class="sub-number">GHS <?php echo number_format($totalRevenue, 2); ?></div>
                                <div class="sub-label">Total Monthly Revenue</div>
                                <small class="text-muted">GHS <?php echo number_format($monthlyRevenue, 2); ?> this month</small>
                            </div>
                            <div class="sub-stat-card">
                                <span class="sub-icon plans"><i class="fas fa-list"></i></span>
                                <div class="sub-number"><?php echo $totalPlans; ?></div>
                                <div class="sub-label">Total Plans</div>
                                <small class="text-muted"><?php echo $activePlans; ?> active</small>
                            </div>
                            <div class="sub-stat-card">
                                <span class="sub-icon active"><i class="fas fa-check-circle"></i></span>
                                <div class="sub-number"><?php echo $activeSubscriptions; ?></div>
                                <div class="sub-label">Active Subscriptions</div>
                                <small class="text-muted"><?php echo $totalTenants; ?> total tenants</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity & Recent Tenants -->
                <div class="row">
                    <div class="col-md-7 col-12">
                        <div class="card-custom">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-history me-2 text-primary"></i>Recent Activity</h6>
                                <span class="text-muted small">
                                    <i class="fas fa-clock me-1"></i> Last 10 activities
                                </span>
                            </div>
                            <div class="card-body-custom">
                                <?php if (empty($recentActivity)): ?>
                                    <div style="text-align:center;padding:20px 0;color:#6c757d;">
                                        <i class="fas fa-clock" style="font-size:32px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                                        <p style="margin:0;">No recent activity</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentActivity as $activity): ?>
                                        <div class="activity-item">
                                            <div class="activity-icon <?php echo $activity['type'] == 'tenant_created' ? 'tenant' : ($activity['type'] == 'school_created' ? 'school' : 'user'); ?>">
                                                <i class="fas <?php echo $activity['type'] == 'tenant_created' ? 'fa-building' : ($activity['type'] == 'school_created' ? 'fa-school' : 'fa-user'); ?>"></i>
                                            </div>
                                            <div class="activity-content">
                                                <div class="activity-name">
                                                    <?php echo htmlspecialchars($activity['name']); ?>
                                                    <span class="badge bg-light text-dark ms-1" style="font-size:10px;"><?php echo htmlspecialchars($activity['category']); ?></span>
                                                </div>
                                                <div class="activity-time">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-5 col-12">
                        <!-- Recent Tenants -->
                        <div class="card-custom mb-3">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-building me-2 text-primary"></i>Recent Tenants</h6>
                                <a href="/platform/tenants/index.php" class="text-primary small">View All <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                            <div class="card-body-custom">
                                <?php if (empty($recentTenants)): ?>
                                    <div style="text-align:center;padding:20px 0;color:#6c757d;">
                                        <i class="fas fa-building" style="font-size:32px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                                        <p style="margin:0;">No tenants found</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentTenants as $tenant): ?>
                                        <div class="tenant-item">
                                            <div>
                                                <div class="tenant-name">
                                                    <a href="/platform/tenants/view.php?id=<?php echo $tenant['id']; ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                                                    </a>
                                                </div>
                                                <div class="tenant-code">
                                                    <i class="fas fa-tag me-1"></i> <?php echo htmlspecialchars($tenant['tenant_code'] ?? 'N/A'); ?>
                                                    <span class="badge-status <?php echo $tenant['status'] ?? 'active'; ?>">
                                                        <?php echo ucfirst($tenant['status'] ?? 'Active'); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="text-muted small">
                                                    <?php echo date('M d, Y', strtotime($tenant['created_at'] ?? 'now')); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Recent Subscriptions -->
                        <div class="card-custom">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-crown me-2 text-warning"></i>Recent Subscriptions</h6>
                                <a href="/platform/subscriptions/index.php" class="text-primary small">View All <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                            <div class="card-body-custom">
                                <?php if (empty($recentSubscriptions)): ?>
                                    <div style="text-align:center;padding:20px 0;color:#6c757d;">
                                        <i class="fas fa-crown" style="font-size:32px;opacity:0.3;display:block;margin-bottom:8px;"></i>
                                        <p style="margin:0;">No active subscriptions</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentSubscriptions as $sub): ?>
                                        <div class="tenant-item">
                                            <div>
                                                <div class="tenant-name">
                                                    <a href="/platform/tenants/view.php?id=<?php echo $sub['tenant_id']; ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($sub['tenant_name']); ?>
                                                    </a>
                                                </div>
                                                <div class="tenant-code">
                                                    <i class="fas fa-crown me-1 text-warning"></i>
                                                    <?php echo htmlspecialchars($sub['plan_name']); ?>
                                                    <span class="badge-status <?php echo $sub['status'] == 'active' ? 'active' : 'trial'; ?>">
                                                        <?php echo ucfirst($sub['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div>
                                                <span class="text-muted small">
                                                    <?php echo date('M d, Y', strtotime($sub['created_at'] ?? 'now')); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card-custom mt-3">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-bolt me-2 text-warning"></i>Quick Actions</h6>
                    </div>
                    <div class="card-body-custom">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="/platform/tenants/register.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus me-1"></i> Add Tenant
                            </a>
                            <a href="/platform/tenants/index.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-building me-1"></i> Manage Tenants
                            </a>
                            <a href="/platform/domains/index.php" class="btn btn-info btn-sm">
                                <i class="fas fa-globe me-1"></i> Manage Domains
                            </a>
                            <a href="/platform/domains/create.php" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-plus me-1"></i> Add Domain
                            </a>
                            <a href="/platform/approvals/index.php" class="btn btn-warning btn-sm">
                                <i class="fas fa-check-double me-1"></i> Approvals
                                <?php if ($pendingApprovals > 0): ?>
                                    <span class="badge bg-dark text-white ms-1"><?php echo $pendingApprovals; ?></span>
                                <?php endif; ?>
                            </a>
                            <a href="/platform/subscriptions/index.php" class="btn btn-success btn-sm">
                                <i class="fas fa-crown me-1"></i> Manage Plans
                            </a>
                            <a href="/platform/subscriptions/create.php" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-plus me-1"></i> Create Plan
                            </a>
                            <a href="/platform/users/index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-users me-1"></i> Manage Users
                            </a>
                            <a href="/platform/settings/index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-cog me-1"></i> Settings
                            </a>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ================================================
        // SIDEBAR TOGGLE (toggleSidebar() is provided by the
        // partial at app/views/partials/platform-sidebar.php.)
        // ================================================
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