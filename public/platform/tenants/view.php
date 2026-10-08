<?php

/**
 * Tenant View - View individual tenant details
 * 
 * @package EduTrack
 * @subpackage Platform\Tenants
 * @version 2.1
 * @filepath public/platform/tenants/view.php
 *
 * v2.1 change (2026-10-08) [SWEEP X-1 + SIDEBAR + CSRF + AUTH]:
 *   Platform-tenants sweep, X-1 in full, plus sidebar
 *   reconciliation, plus the super-admin auth guard, plus the CSRF
 *   field on the two JS-built forms, plus four repairs.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   AUTH: the is_super_admin guard is added immediately after the
 *   logged_in guard. Prior to this change, this file — unlike every
 *   other file in tenants/ — carried only the logged_in guard. Any
 *   authenticated user who knew a tenant id could view that
 *   tenant's detail page, counts, schools list, and recent activity.
 *   The tenant id is a small enumerable integer passed in the URL.
 *   The added guard matches tenants/index.php, tenants/register.php,
 *   and the two approvals/ files:
 *     if (!isset($_SESSION['is_super_admin']) ||
 *         $_SESSION['is_super_admin'] !== true) {
 *         header('Location: /platform/tenant/dashboard.php');
 *         exit;
 *     }
 *
 *   Sidebar: the inline sidebar this page carried — 9 items across
 *   3 groups (Main: Dashboard, Tenants, Users; Institution: Schools,
 *   Campuses; System: Audit Logs, Monitoring, Settings), brand
 *   heading "EduTrack", width 260px — is replaced by a single
 *   require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System),
 *   including an Approvals item in the Main group, brand heading
 *   "Student 360", plus the mobile toggle, the sidebar footer, and
 *   the toggleSidebar() JS.
 *
 *   $currentPage stays 'tenants' so the Tenants item in the
 *   canonical partial is marked active on this page.
 *   $pendingApprovals = 0 is set before the partial, matching the
 *   swept convention.
 *
 *   The page's .sidebar, .sidebar-toggle, .sidebar-header, .nav,
 *   .nav-label, .nav-link, .sidebar-footer, .user-info, .user-avatar,
 *   .user-name, .user-role, and .logout-btn CSS rules stay in the
 *   page's <style> block, because the partial ships markup only and
 *   no CSS.
 *
 *   The inline toggleSidebar() function, the inline click-outside
 *   handler, and the inline resize handler are removed, because the
 *   partial provides them. The page's logout(), loadUserInfo(),
 *   selectTenant(), deleteTenant(), and the DOMContentLoaded
 *   handler are kept — they are page-scoped and do not conflict
 *   with the partial.
 *
 *   CSRF field. The two JS-built forms in selectTenant() and
 *   deleteTenant() now carry a hidden csrf_token input. The token
 *   value is emitted server-side by csrf_token() when it exists, or
 *   by Security::csrfToken() otherwise, matching the same
 *   function_exists()/class_exists() dispatch used in
 *   public/platform/approvals/approve.php v1.0 and
 *   public/platform/tenants/index.php v2.1.
 *
 *   NOTE on record: the deleteTenant() target /platform/tenants/
 *   delete.php is in the tenants/ folder listing. The selectTenant()
 *   target /platform/tenants/select.php is NOT in the tenants/
 *   folder listing — the listing carries six files (delete.php,
 *   domain_management.php, edit.php, index.php, register.php,
 *   view.php). The csrf_token field added to the selectTenant() form
 *   is present, but whether its handler verifies it cannot be stated
 *   until select.php is read.
 *
 *   Repairs:
 *     - logout() redirect standardised from the dynamic
 *       '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php'
 *       form to the relative '/platform/login.php' form, matching
 *       the file's own server-side guard and the approvals/index.php
 *       convention. The now-unused $protocol and $host variables
 *       are removed.
 *     - $userFirstName = $_SESSION['first_name'] ?? 'Super'; added,
 *       and $userAvatar derivation changed from
 *       substr($currentUser, 0, 1) to
 *       strtoupper(substr($userFirstName, 0, 1)), matching the
 *       other swept platform pages.
 *     - $pageTitle standardised from the dynamic
 *       'Tenant - ' . $tenant['tenant_name'] form to the static
 *       'Tenant Details - Student 360 Platform' form, matching the
 *       swept convention.
 *     - The inline sidebar footer role string was 'Administrator';
 *       the canonical partial carries 'Super Administrator'. The
 *       string on this page now comes from the partial.
 *
 *   Open items on the record (NOT changed by this sweep):
 *     - The ?updated=1 alert renders twice on a single load. Once
 *       from the top-of-page PHP block, and once from the
 *       DOMContentLoaded handler. This is a visual defect; the fix
 *       is to remove one of the two render paths.
 *     - $activeTab is read from $_GET['tab'] without a whitelist.
 *       The three tab branches are checked via === so an unknown
 *       value renders no tab content. Not a security defect.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
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
    header('Location: /platform/login.php');
    exit;
}

// Super Admin check - matches tenants/index.php and tenants/register.php
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// Get tenant ID from URL
$tenantId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no tenant ID, redirect to tenants list
if (!$tenantId) {
    header('Location: /platform/tenants/index.php');
    exit;
}

// =============================================
// LOAD CONFIG AND DATABASE HELPER
// =============================================
$projectRoot = dirname(__DIR__, 3);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// =============================================
// GET TENANT DETAILS
// =============================================
$tenant = $db->fetchOne(
    "SELECT * FROM tenants WHERE id = ? AND deleted_at IS NULL",
    [$tenantId]
);

// If tenant not found, redirect
if (!$tenant) {
    header('Location: /platform/tenants/index.php?error=tenant_not_found');
    exit;
}

// =============================================
// GET TENANT STATS
// =============================================
$stats = [
    'total_schools' => $db->getValue(
        "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
    ) ?? 0,
    'active_schools' => $db->getValue(
        "SELECT COUNT(*) FROM schools WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'active'",
        [$tenantId]
    ) ?? 0,
    'total_campuses' => $db->getValue(
        "SELECT COUNT(*) FROM campuses c 
         JOIN schools s ON c.school_id = s.id 
         WHERE s.tenant_id = ? AND c.deleted_at IS NULL",
        [$tenantId]
    ) ?? 0,
    'total_users' => $db->getValue(
        "SELECT COUNT(*) FROM platform_users WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
    ) ?? 0,
    'total_students' => $db->getValue(
        "SELECT COUNT(*) FROM students WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
    ) ?? 0,
    'total_staff' => $db->getValue(
        "SELECT COUNT(*) FROM staff WHERE tenant_id = ? AND deleted_at IS NULL",
        [$tenantId]
    ) ?? 0,
];

// =============================================
// GET SCHOOLS UNDER THIS TENANT
// =============================================
$schools = $db->fetchAll(
    "SELECT s.*, 
            (SELECT COUNT(*) FROM campuses c WHERE c.school_id = s.id AND c.deleted_at IS NULL) as campus_count
     FROM schools s 
     WHERE s.tenant_id = ? AND s.deleted_at IS NULL 
     ORDER BY s.school_name ASC",
    [$tenantId]
);

// =============================================
// GET RECENT ACTIVITY
// =============================================
$recentActivity = $db->fetchAll(
    "SELECT 
        'school_created' as action_type,
        s.school_name as resource_name,
        s.created_at as created_at,
        'School' as resource_type
     FROM schools s
     WHERE s.tenant_id = ? AND s.deleted_at IS NULL
     UNION ALL
     SELECT 
        'user_created' as action_type,
        u.username as resource_name,
        u.created_at as created_at,
        'User' as resource_type
     FROM platform_users u
     WHERE u.tenant_id = ? AND u.deleted_at IS NULL
     ORDER BY created_at DESC
     LIMIT 10",
    [$tenantId, $tenantId]
);

$pageTitle = 'Tenant Details - Student 360 Platform';
$currentPage = 'tenants';

$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$currentTenantId = $_SESSION['tenant_id'] ?? 0;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';

// Country mapping for display
$countries = [1 => 'Ghana', 2 => 'Nigeria', 3 => 'Kenya', 4 => 'South Africa', 5 => 'UK', 6 => 'USA', 7 => 'Canada', 8 => 'Australia'];

// Variables read by the platform sidebar partial
$pendingApprovals = 0;

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

// CSRF token for the JS-built select/delete forms
$csrfToken = '';
if (function_exists('csrf_token')) {
    $csrfToken = csrf_token();
} elseif (class_exists('Security') && method_exists('Security', 'csrfToken')) {
    $csrfToken = Security::csrfToken();
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
            padding: 0 0 24px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
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
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.3s;
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

        .btn-danger {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-danger:hover {
            background: #c82333;
            color: #fff;
        }

        /* ================================================ */
        /* TENANT HEADER */
        /* ================================================ */
        .tenant-header {
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

        .tenant-header .tenant-info {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .tenant-header .tenant-info .tenant-icon {
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

        .tenant-header .tenant-info .tenant-name {
            font-weight: 700;
            font-size: 22px;
            color: #1a1a2e;
        }

        .tenant-header .tenant-info .tenant-code {
            font-size: 13px;
            color: #6c757d;
        }

        .tenant-header .tenant-info .tenant-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 4px;
            font-size: 13px;
            color: #6c757d;
        }

        .tenant-header .tenant-info .tenant-meta i {
            width: 16px;
            margin-right: 4px;
        }

        .tenant-header .tenant-status {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
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

        .current-tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        /* ================================================ */
        /* STATS ROW */
        /* ================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
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
        /* TABS */
        /* ================================================ */
        .tenant-tabs {
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e9ecef;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .tenant-tabs .tab-header {
            display: flex;
            flex-wrap: wrap;
            border-bottom: 2px solid #f0f2f5;
            background: #fafbfc;
            padding: 0 8px;
        }

        .tenant-tabs .tab-header .tab-link {
            padding: 14px 20px;
            color: #6c757d;
            font-weight: 500;
            font-size: 13px;
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .tenant-tabs .tab-header .tab-link:hover {
            color: #1a1a2e;
            background: #f8f9fa;
        }

        .tenant-tabs .tab-header .tab-link.active {
            color: #4facfe;
            border-bottom-color: #4facfe;
            background: transparent;
        }

        .tenant-tabs .tab-header .tab-link i {
            font-size: 14px;
        }

        .tenant-tabs .tab-content {
            padding: 24px;
        }

        /* ================================================ */
        /* SCHOOL LIST */
        /* ================================================ */
        .school-item {
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

        .school-item:hover {
            background: #fff;
            border-color: #4facfe;
        }

        .school-item .school-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .school-item .school-name a {
            color: #1a1a2e;
            text-decoration: none;
        }

        .school-item .school-name a:hover {
            color: #4facfe;
        }

        .school-item .school-code {
            font-size: 12px;
            color: #6c757d;
        }

        .school-item .school-meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: #6c757d;
            flex-wrap: wrap;
        }

        .school-item .school-meta i {
            margin-right: 4px;
        }

        .school-item .school-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .school-item .school-actions .btn {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 8px;
        }

        /* ================================================ */
        /* ACTIVITY LIST */
        /* ================================================ */
        .activity-item {
            display: flex;
            align-items: flex-start;
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
            gap: 12px;
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
            flex-shrink: 0;
            font-size: 14px;
            color: #fff;
        }

        .activity-item .activity-icon.school {
            background: #4facfe;
        }

        .activity-item .activity-icon.user {
            background: #28a745;
        }

        .activity-item .activity-icon.default {
            background: #6c757d;
        }

        .activity-item .activity-content {
            flex: 1;
        }

        .activity-item .activity-content .action-text {
            font-size: 14px;
            color: #1a1a2e;
        }

        .activity-item .activity-content .action-text strong {
            font-weight: 600;
        }

        .activity-item .activity-content .action-time {
            font-size: 12px;
            color: #6c757d;
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
                grid-template-columns: repeat(3, 1fr);
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
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .tenant-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .tenant-header .tenant-status {
                justify-content: flex-start;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }

            .tenant-tabs .tab-header {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding: 0 4px;
            }

            .tenant-tabs .tab-header .tab-link {
                padding: 12px 14px;
                font-size: 12px;
                white-space: nowrap;
            }

            .tenant-tabs .tab-header .tab-link span {
                display: none;
            }

            .tenant-tabs .tab-content {
                padding: 16px;
            }

            .school-item {
                flex-direction: column;
                align-items: stretch;
            }

            .school-item .school-actions {
                justify-content: flex-start;
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
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 12px 14px;
            }

            .stat-card .stat-number {
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
                        <h1><i class="fas fa-building me-2"></i>Tenant Details</h1>
                        <p>View and manage tenant information</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenants/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Tenants
                        </a>
                        <a href="/platform/tenants/edit.php?id=<?php echo $tenantId; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-edit me-2"></i> Edit Tenant
                        </a>
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i> Tenant updated successfully!
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i> <?php echo $_SESSION['success'];
                                                                        unset($_SESSION['success']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tenant Header -->
                <div class="tenant-header">
                    <div class="tenant-info">
                        <div class="tenant-icon"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="tenant-name">
                                <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                                <?php if ($currentTenantId == $tenantId): ?>
                                    <span class="current-tenant-badge"><i class="fas fa-check-circle"></i> Current Tenant</span>
                                <?php endif; ?>
                            </div>
                            <div class="tenant-code">Code: <?php echo htmlspecialchars($tenant['tenant_code'] ?? 'N/A'); ?> | ID: <?php echo $tenantId; ?></div>
                            <div class="tenant-meta">
                                <?php if (!empty($tenant['email'])): ?>
                                    <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($tenant['email']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($tenant['phone'])): ?>
                                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($tenant['phone']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($tenant['city'])): ?>
                                    <span><i class="fas fa-city"></i> <?php echo htmlspecialchars($tenant['city']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($tenant['region'])): ?>
                                    <span><i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($tenant['region']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($tenant['country_id'])): ?>
                                    <span><i class="fas fa-globe"></i> <?php echo $countries[$tenant['country_id']] ?? 'N/A'; ?></span>
                                <?php endif; ?>
                                <span><i class="fas fa-calendar"></i> Created: <?php echo date('M d, Y', strtotime($tenant['created_at'] ?? 'now')); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="tenant-status">
                        <span class="badge-status <?php echo $tenant['status'] ?? 'active'; ?>">
                            <i class="fas fa-circle" style="font-size:8px;margin-right:6px;"></i>
                            <?php echo ucfirst($tenant['status'] ?? 'Active'); ?>
                        </span>
                        <?php if ($currentTenantId != $tenantId): ?>
                            <button class="btn btn-success btn-sm" onclick="selectTenant(<?php echo $tenantId; ?>, '<?php echo htmlspecialchars($tenant['tenant_name']); ?>')">
                                <i class="fas fa-check me-1"></i> Switch to this Tenant
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-danger btn-sm" onclick="deleteTenant(<?php echo $tenantId; ?>, '<?php echo htmlspecialchars($tenant['tenant_name']); ?>')">
                            <i class="fas fa-trash me-1"></i> Delete
                        </button>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-school"></i></div>
                        <div class="stat-number"><?php echo $stats['total_schools']; ?></div>
                        <div class="stat-label">Total Schools</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-check-circle" style="color:#28a745;"></i></div>
                        <div class="stat-number"><?php echo $stats['active_schools']; ?></div>
                        <div class="stat-label">Active Schools</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="stat-number"><?php echo $stats['total_campuses']; ?></div>
                        <div class="stat-label">Total Campuses</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-number"><?php echo $stats['total_users']; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                        <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                        <div class="stat-label">Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                        <div class="stat-number"><?php echo $stats['total_staff']; ?></div>
                        <div class="stat-label">Staff</div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tenant-tabs">
                    <div class="tab-header">
                        <a class="tab-link <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" href="?id=<?php echo $tenantId; ?>&tab=overview">
                            <i class="fas fa-info-circle"></i> <span>Overview</span>
                        </a>
                        <a class="tab-link <?php echo $activeTab === 'schools' ? 'active' : ''; ?>" href="?id=<?php echo $tenantId; ?>&tab=schools">
                            <i class="fas fa-school"></i> <span>Schools</span>
                            <span class="badge bg-secondary"><?php echo $stats['total_schools']; ?></span>
                        </a>
                        <a class="tab-link <?php echo $activeTab === 'activity' ? 'active' : ''; ?>" href="?id=<?php echo $tenantId; ?>&tab=activity">
                            <i class="fas fa-history"></i> <span>Recent Activity</span>
                        </a>
                    </div>

                    <div class="tab-content">
                        <?php if ($activeTab === 'overview'): ?>
                            <!-- Overview Tab -->
                            <div class="row">
                                <div class="col-md-6 col-12">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Tenant Information</h6>
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <td style="width:120px;font-weight:500;">Tenant Name</td>
                                            <td><?php echo htmlspecialchars($tenant['tenant_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Tenant Code</td>
                                            <td><?php echo htmlspecialchars($tenant['tenant_code'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Legal Name</td>
                                            <td><?php echo htmlspecialchars($tenant['legal_name'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Status</td>
                                            <td><span class="badge-status <?php echo $tenant['status'] ?? 'active'; ?>"><?php echo ucfirst($tenant['status'] ?? 'Active'); ?></span></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Created</td>
                                            <td><?php echo date('M d, Y H:i', strtotime($tenant['created_at'] ?? 'now')); ?></td>
                                        </tr>
                                        <?php if (!empty($tenant['updated_at'])): ?>
                                            <tr>
                                                <td style="font-weight:500;">Updated</td>
                                                <td><?php echo date('M d, Y H:i', strtotime($tenant['updated_at'])); ?></td>
                                            </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                                <div class="col-md-6 col-12">
                                    <h6 class="fw-bold mb-3"><i class="fas fa-address-card me-2 text-primary"></i>Contact Information</h6>
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <td style="width:120px;font-weight:500;">Email</td>
                                            <td><?php echo htmlspecialchars($tenant['email'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Phone</td>
                                            <td><?php echo htmlspecialchars($tenant['phone'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Address</td>
                                            <td><?php echo htmlspecialchars($tenant['postal_address'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">City</td>
                                            <td><?php echo htmlspecialchars($tenant['city'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Region</td>
                                            <td><?php echo htmlspecialchars($tenant['region'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Country</td>
                                            <td><?php echo $countries[$tenant['country_id'] ?? 0] ?? 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <td style="font-weight:500;">Website</td>
                                            <td><?php echo htmlspecialchars($tenant['website'] ?? 'N/A'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-12">
                                    <h6 class="fw-bold mb-2"><i class="fas fa-align-left me-2 text-primary"></i>Description</h6>
                                    <p style="color:#6c757d;"><?php echo nl2br(htmlspecialchars($tenant['description'] ?? 'No description provided.')); ?></p>
                                </div>
                            </div>

                        <?php elseif ($activeTab === 'schools'): ?>
                            <!-- Schools Tab -->
                            <?php if (empty($schools)): ?>
                                <div style="text-align:center; padding:40px 20px; color:#6c757d;">
                                    <i class="fas fa-school" style="font-size:48px; opacity:0.3; display:block; margin-bottom:16px;"></i>
                                    <h5 style="font-weight:600; color:#1a1a2e;">No Schools Found</h5>
                                    <p>No schools have been created for this tenant yet.</p>
                                    <a href="/platform/schools/create.php?tenant_id=<?php echo $tenantId; ?>" class="btn btn-primary mt-2">
                                        <i class="fas fa-plus me-2"></i> Create School
                                    </a>
                                </div>
                            <?php else: ?>
                                <?php foreach ($schools as $school): ?>
                                    <div class="school-item">
                                        <div>
                                            <div class="school-name">
                                                <a href="/platform/schools/view.php?id=<?php echo $school['id']; ?>">
                                                    <?php echo htmlspecialchars($school['school_name']); ?>
                                                </a>
                                            </div>
                                            <div class="school-code">
                                                <i class="fas fa-tag"></i> <?php echo htmlspecialchars($school['school_code'] ?? 'N/A'); ?>
                                                <span class="badge-status <?php echo $school['status'] ?? 'active'; ?>" style="font-size:10px;padding:2px 10px;">
                                                    <?php echo ucfirst($school['status'] ?? 'Active'); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="school-meta">
                                            <span><i class="fas fa-map-marker-alt"></i> <?php echo $school['campus_count'] ?? 0; ?> Campuses</span>
                                            <?php if (!empty($school['city'])): ?>
                                                <span><i class="fas fa-city"></i> <?php echo htmlspecialchars($school['city']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($school['phone'])): ?>
                                                <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($school['phone']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="school-actions">
                                            <a href="/platform/schools/view.php?id=<?php echo $school['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i></a>
                                            <a href="/platform/schools/edit.php?id=<?php echo $school['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit"></i></a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        <?php elseif ($activeTab === 'activity'): ?>
                            <!-- Activity Tab -->
                            <?php if (empty($recentActivity)): ?>
                                <div style="text-align:center; padding:40px 20px; color:#6c757d;">
                                    <i class="fas fa-clock" style="font-size:48px; opacity:0.3; display:block; margin-bottom:16px;"></i>
                                    <h5 style="font-weight:600; color:#1a1a2e;">No Activity</h5>
                                    <p>No recent activity for this tenant.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentActivity as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon <?php if (strpos($activity['action_type'], 'school') !== false) echo 'school';
                                                                    elseif (strpos($activity['action_type'], 'user') !== false) echo 'user';
                                                                    else echo 'default'; ?>">
                                            <i class="fas <?php if (strpos($activity['action_type'], 'school') !== false) echo 'fa-school';
                                                            elseif (strpos($activity['action_type'], 'user') !== false) echo 'fa-user';
                                                            else echo 'fa-plus'; ?>"></i>
                                        </div>
                                        <div class="activity-content">
                                            <div class="action-text">
                                                <strong><?php echo htmlspecialchars($activity['resource_name']); ?></strong>
                                                was <?php echo str_replace('_', ' ', $activity['action_type']); ?>
                                                <?php if ($activity['resource_type']): ?>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($activity['resource_type']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="action-time">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // CSRF token for the JS-built select/delete forms.
        // Populated server-side from csrf_token() or Security::csrfToken().
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '/platform/login.php';
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

        // ================================================
        // SELECT TENANT
        // ================================================
        function selectTenant(tenantId, tenantName) {
            if (confirm('Switch to tenant: ' + tenantName + '?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/platform/tenants/select.php';
                const tenantInput = document.createElement('input');
                tenantInput.type = 'hidden';
                tenantInput.name = 'tenant_id';
                tenantInput.value = tenantId;
                form.appendChild(tenantInput);
                const nameInput = document.createElement('input');
                nameInput.type = 'hidden';
                nameInput.name = 'tenant_name';
                nameInput.value = tenantName;
                form.appendChild(nameInput);
                const redirectInput = document.createElement('input');
                redirectInput.type = 'hidden';
                redirectInput.name = 'redirect';
                redirectInput.value = '/platform/tenants/view.php?id=' + tenantId;
                form.appendChild(redirectInput);
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = CSRF_TOKEN;
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // ================================================
        // DELETE TENANT
        // ================================================
        function deleteTenant(tenantId, tenantName) {
            if (confirm('Are you sure you want to delete tenant: "' + tenantName + '"?\n\nThis action cannot be undone!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/platform/tenants/delete.php';
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'tenant_id';
                idInput.value = tenantId;
                form.appendChild(idInput);
                const confirmInput = document.createElement('input');
                confirmInput.type = 'hidden';
                confirmInput.name = 'confirm';
                confirmInput.value = 'yes';
                form.appendChild(confirmInput);
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = CSRF_TOKEN;
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            <?php if (isset($_GET['updated']) && $_GET['updated'] == 1): ?>
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success alert-dismissible fade show';
                alertDiv.style.borderRadius = '12px';
                alertDiv.style.border = 'none';
                alertDiv.style.boxShadow = '0 4px 20px rgba(0,0,0,0.06)';
                alertDiv.innerHTML = `
                    <i class="fas fa-check-circle me-2"></i> Tenant updated successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                document.getElementById('alertContainer').appendChild(alertDiv);
                setTimeout(() => {
                    alertDiv.classList.remove('show');
                    setTimeout(() => alertDiv.remove(), 300);
                }, 5000);
            <?php endif; ?>
        });
    </script>
</body>

</html>