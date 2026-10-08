<?php

/**
 * Tenants Management - Super Admin Tenant List
 * 
 * This page is ONLY for Super Admin (Platform Admin).
 * Shows ALL tenants across the platform with full control.
 * 
 * @package EduTrack
 * @subpackage Platform\Tenants
 * @version 2.1
 * @filepath public/platform/tenants/index.php
 *
 * v2.1 change (2026-10-08) [SWEEP X-1 + SIDEBAR + CSRF]:
 *   Platform-tenants sweep, X-1 in full, plus sidebar
 *   reconciliation, plus the CSRF field added to the two JS-built
 *   forms, plus three repairs.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 9 items across
 *   4 groups (Main: Dashboard, Tenants, Users; Institution: Schools,
 *   Campuses; Workflow: Approvals only when $pendingApprovals > 0;
 *   System: Audit Logs, Monitoring, Settings), brand heading
 *   "EduTrack", width 260px — is replaced by a single require_once
 *   of the shared partial at app/views/partials/platform-sidebar.php
 *   v1.0. That partial carries the canonical platform-root sidebar
 *   shape: 11 items across 4 groups (Main, Institution, Management,
 *   System), including an Approvals item in the Main group, brand
 *   heading "Student 360", plus the mobile toggle, the sidebar
 *   footer, and the toggleSidebar() JS.
 *
 *   $currentPage stays 'tenants' so the Tenants item in the
 *   canonical partial is marked active on this page.
 *   $pendingApprovals is already set above the sidebar block and is
 *   read by the partial's badge with a fallback of 0.
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
 *   applyFilters(), resetFilters(), setView(), selectTenant(),
 *   deleteTenant(), and the DOMContentLoaded handler are kept —
 *   they are page-scoped and do not conflict with the partial.
 *
 *   CSRF field. The two JS-built forms in selectTenant() and
 *   deleteTenant() now carry a hidden csrf_token input. The token
 *   value is emitted server-side by csrf_token() when it exists, or
 *   by Security::csrfToken() otherwise, matching the same
 *   function_exists()/class_exists() dispatch used in
 *   public/platform/approvals/approve.php v1.0.
 *
 *   NOTE on record: the deleteTenant() target /platform/tenants/
 *   delete.php is in the tenants/ folder listing. The selectTenant()
 *   target /platform/tenants/select.php is NOT in the tenants/
 *   folder listing — the listing carries six files (delete.php,
 *   domain_management.php, edit.php, index.php, register.php,
 *   view.php). The csrf_token field added to the selectTenant() form
 *   is present, but whether its handler verifies it cannot be stated
 *   until select.php is read. delete.php will need its own
 *   verify_csrf() call to accept the token this page now emits.
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
 *       other swept platform pages. The avatar letter now comes
 *       from the first name rather than from the login handle.
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
// AUTHENTICATION CHECK - Super Admin Only
// =============================================
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Check if user is Super Admin
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    // Redirect tenant admins to their dashboard
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Tenants - Student 360 Platform';
$currentPage = 'tenants';

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
// GET FILTERS
// =============================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$viewMode = isset($_GET['view']) ? $_GET['view'] : 'grid';
$tenantId = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;

// =============================================
// GET ALL TENANTS (Super Admin sees ALL)
// =============================================
$sql = "SELECT 
            t.*,
            (SELECT COUNT(*) FROM schools WHERE tenant_id = t.id AND deleted_at IS NULL) as school_count,
            (SELECT COUNT(*) FROM campuses c 
             JOIN schools s ON c.school_id = s.id 
             WHERE s.tenant_id = t.id AND c.deleted_at IS NULL) as campus_count,
            (SELECT COUNT(*) FROM platform_users WHERE tenant_id = t.id AND deleted_at IS NULL) as user_count,
            (SELECT COUNT(*) FROM students WHERE tenant_id = t.id AND deleted_at IS NULL) as student_count,
            (SELECT COUNT(*) FROM staff WHERE tenant_id = t.id AND deleted_at IS NULL) as staff_count
        FROM tenants t 
        WHERE t.deleted_at IS NULL";
$params = [];

if (!empty($search)) {
    $sql .= " AND (t.tenant_name LIKE ? OR t.tenant_code LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if (!empty($statusFilter)) {
    $sql .= " AND t.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY t.created_at DESC";
$tenants = $db->fetchAll($sql, $params);

// =============================================
// GET PENDING APPROVALS COUNT (with error handling)
// =============================================
$pendingApprovals = 0;
$pendingSchools = 0;
$pendingCampuses = 0;

try {
    // Check if school_requests table exists
    $tableExists = $db->getValue(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = ? AND table_name = 'school_requests'",
        [DB_NAME]
    );

    if ($tableExists > 0) {
        $pendingSchools = $db->getValue(
            "SELECT COUNT(*) FROM school_requests WHERE status = 'pending' AND (deleted_at IS NULL OR deleted_at = '')"
        ) ?? 0;

        // Check if campus_requests table exists
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
    }
} catch (Exception $e) {
    // Tables don't exist yet - ignore
    $pendingApprovals = 0;
    $pendingSchools = 0;
    $pendingCampuses = 0;
}

// =============================================
// STATUSES
// =============================================
$statuses = [
    'active' => 'Active',
    'pending' => 'Pending',
    'suspended' => 'Suspended',
    'inactive' => 'Inactive'
];

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$currentTenantId = $_SESSION['tenant_id'] ?? 0;

// Variables read by the platform sidebar partial
$pendingApprovals = $pendingApprovals;

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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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

        .btn-warning {
            background: #ffc107;
            border: none;
            color: #1a1a2e;
        }

        .btn-warning:hover {
            background: #e0a800;
            color: #1a1a2e;
        }

        /* ================================================ */
        /* STATS ROW */
        /* ================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-bottom: 20px;
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

        .stat-card .stat-icon.red {
            background: #fce4ec;
            color: #dc3545;
        }

        .stat-card .stat-icon.teal {
            background: #d0f0f0;
            color: #20c997;
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

        /* ================================================ */
        /* FILTER SECTION */
        /* ================================================ */
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

        /* ================================================ */
        /* VIEW TOGGLE */
        /* ================================================ */
        .view-toggle {
            display: flex;
            gap: 4px;
            background: #f0f2f5;
            border-radius: 8px;
            padding: 3px;
        }

        .view-toggle .btn {
            border: none;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 13px;
            background: transparent;
            color: #6c757d;
            transition: all 0.3s;
        }

        .view-toggle .btn.active {
            background: #fff;
            color: #4facfe;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .view-toggle .btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.5);
        }

        /* ================================================ */
        /* TENANT GRID */
        /* ================================================ */
        .tenant-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .tenant-grid .tenant-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px 24px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            text-decoration: none;
            color: #1a1a2e;
            display: block;
        }

        .tenant-grid .tenant-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
            border-color: #4facfe;
        }

        /* ================================================ */
        /* TENANT LIST */
        /* ================================================ */
        .tenant-list .tenant-card {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 12px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
            text-decoration: none;
            color: #1a1a2e;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tenant-list .tenant-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-color: #4facfe;
        }

        .tenant-list .tenant-card .tenant-info {
            flex: 1;
            min-width: 200px;
        }

        .tenant-list .tenant-card .tenant-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .tenant-card .tenant-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-card .tenant-code {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }

        .tenant-card .tenant-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 6px;
            font-size: 13px;
            color: #6c757d;
        }

        .tenant-card .tenant-meta i {
            width: 16px;
            margin-right: 4px;
        }

        .tenant-card .tenant-actions .btn {
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 8px;
        }

        /* ================================================ */
        /* BADGE STATUS */
        /* ================================================ */
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

        .current-tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }

        .pending-approval-badge {
            background: #ffc107;
            color: #1a1a2e;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }

        /* ================================================ */
        /* RESPONSIVE */
        /* ================================================ */
        @media (max-width: 1200px) {
            .stats-row {
                grid-template-columns: repeat(3, 1fr);
            }

            .tenant-grid {
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

            .stats-row {
                grid-template-columns: 1fr 1fr;
            }

            .tenant-grid {
                grid-template-columns: 1fr;
            }

            .tenant-list .tenant-card {
                flex-direction: column;
                align-items: stretch;
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

            .stats-row {
                grid-template-columns: 1fr;
            }

            .tenant-card {
                padding: 14px 16px;
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
                        <h1><i class="fas fa-building me-2"></i>Tenants</h1>
                        <p>Manage all tenants on the platform</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenants/register.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Tenant
                        </a>
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
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
                </div>

                <!-- Stats -->
                <div class="stats-row" id="statsRow">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="stat-number"><?php echo count($tenants); ?></div>
                            <div class="stat-label">Total Tenants</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number">
                                <?php
                                $activeCount = 0;
                                foreach ($tenants as $t) {
                                    if ($t['status'] === 'active') $activeCount++;
                                }
                                echo $activeCount;
                                ?>
                            </div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="stat-number">
                                <?php
                                $pendingCount = 0;
                                foreach ($tenants as $t) {
                                    if ($t['status'] === 'pending') $pendingCount++;
                                }
                                echo $pendingCount;
                                ?>
                            </div>
                            <div class="stat-label">Pending</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="stat-number">
                                <?php
                                $totalSchools = 0;
                                foreach ($tenants as $t) {
                                    $totalSchools += $t['school_count'] ?? 0;
                                }
                                echo $totalSchools;
                                ?>
                            </div>
                            <div class="stat-label">Total Schools</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon teal"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number">
                                <?php
                                $totalUsers = 0;
                                foreach ($tenants as $t) {
                                    $totalUsers += $t['user_count'] ?? 0;
                                }
                                echo $totalUsers;
                                ?>
                            </div>
                            <div class="stat-label">Total Users</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $pendingApprovals; ?></div>
                            <div class="stat-label">Pending Approvals</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <div class="filter-group search-group">
                        <label for="searchInput"><i class="fas fa-search"></i></label>
                        <input type="text" class="form-control" id="searchInput" name="search"
                            placeholder="Search tenants..." value="<?php echo htmlspecialchars($search ?? ''); ?>" autocomplete="off">
                    </div>
                    <div class="filter-group">
                        <label for="statusFilter">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="">All</option>
                            <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo (isset($statusFilter) && $statusFilter == $key) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="button" class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                        <div class="view-toggle">
                            <button class="btn <?php echo $viewMode == 'grid' ? 'active' : ''; ?>" onclick="setView('grid')" title="Grid View"><i class="fas fa-th"></i></button>
                            <button class="btn <?php echo $viewMode == 'list' ? 'active' : ''; ?>" onclick="setView('list')" title="List View"><i class="fas fa-list"></i></button>
                        </div>
                    </div>
                </div>

                <!-- Tenants Container -->
                <div id="tenantsContainer" class="<?php echo $viewMode == 'grid' ? 'tenant-grid' : 'tenant-list'; ?>">
                    <?php if (empty($tenants)): ?>
                        <div class="card-custom" style="padding:60px 40px;text-align:center;grid-column:1/-1;">
                            <i class="fas fa-building" style="font-size:64px;color:#dee2e6;margin-bottom:20px;display:block;"></i>
                            <h4 style="color:#1a1a2e;margin-bottom:8px;">No Tenants Found</h4>
                            <p style="color:#6c757d;font-size:14px;max-width:400px;margin:0 auto 20px;">
                                No tenants have been registered yet. Create your first tenant to get started.
                            </p>
                            <a href="/platform/tenants/register.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-plus me-2"></i> Create Tenant
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tenants as $tenant): ?>
                            <div class="tenant-card">
                                <div class="tenant-info">
                                    <div class="tenant-name">
                                        <?php echo htmlspecialchars($tenant['tenant_name']); ?>
                                        <?php if ($currentTenantId == $tenant['id']): ?>
                                            <span class="current-tenant-badge"><i class="fas fa-check-circle"></i> Current</span>
                                        <?php endif; ?>
                                        <?php if (($tenant['school_count'] ?? 0) > 0): ?>
                                            <span class="pending-approval-badge"><i class="fas fa-school"></i> <?php echo $tenant['school_count']; ?> Schools</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="tenant-code">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($tenant['tenant_code'] ?? 'N/A'); ?>
                                        <span class="badge-status <?php echo $tenant['status'] ?? 'active'; ?>">
                                            <i class="fas fa-circle" style="font-size:8px;margin-right:4px;"></i>
                                            <?php echo ucfirst($tenant['status'] ?? 'Active'); ?>
                                        </span>
                                    </div>
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
                                    </div>
                                    <div class="tenant-meta" style="margin-top:4px;">
                                        <span><i class="fas fa-school"></i> <?php echo $tenant['school_count'] ?? 0; ?> Schools</span>
                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo $tenant['campus_count'] ?? 0; ?> Campuses</span>
                                        <span><i class="fas fa-users"></i> <?php echo $tenant['user_count'] ?? 0; ?> Users</span>
                                        <span><i class="fas fa-user-graduate"></i> <?php echo $tenant['student_count'] ?? 0; ?> Students</span>
                                        <span><i class="fas fa-user-tie"></i> <?php echo $tenant['staff_count'] ?? 0; ?> Staff</span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($tenant['created_at'] ?? 'now')); ?></span>
                                    </div>
                                </div>
                                <div class="tenant-actions">
                                    <a href="/platform/tenants/view.php?id=<?php echo $tenant['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-eye"></i> View</a>
                                    <a href="/platform/tenants/edit.php?id=<?php echo $tenant['id']; ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                    <a href="/platform/schools/index.php?tenant_id=<?php echo $tenant['id']; ?>" class="btn btn-outline-info btn-sm"><i class="fas fa-school"></i> Schools</a>
                                    <?php if ($currentTenantId != $tenant['id']): ?>
                                        <button class="btn btn-outline-success btn-sm" onclick="selectTenant(<?php echo $tenant['id']; ?>, '<?php echo htmlspecialchars($tenant['tenant_name']); ?>')"><i class="fas fa-check"></i> Switch</button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-danger btn-sm" onclick="deleteTenant(<?php echo $tenant['id']; ?>, '<?php echo htmlspecialchars($tenant['tenant_name']); ?>')"><i class="fas fa-trash"></i></button>
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
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        // ================================================
        // SELECT TENANT (Super Admin Context Switch)
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
                redirectInput.value = '/platform/tenants/index.php';
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
        // DELETE TENANT (Super Admin Only)
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
        // APPLY FILTERS
        // ================================================
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const view = '<?php echo $viewMode; ?>';
            let url = '/platform/tenants/index.php?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (status) url += 'status=' + encodeURIComponent(status) + '&';
            if (view) url += 'view=' + view;
            window.location.href = url;
        }

        // ================================================
        // RESET FILTERS
        // ================================================
        function resetFilters() {
            window.location.href = '/platform/tenants/index.php?view=<?php echo $viewMode; ?>';
        }

        // ================================================
        // SET VIEW
        // ================================================
        function setView(view) {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('view', view);
            window.location.href = currentUrl.toString();
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            // Set up search input - Enter key triggers filter
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        applyFilters();
                    }
                });
            }

            // Auto-apply filter on status change
            document.getElementById('statusFilter').addEventListener('change', function() {
                applyFilters();
            });
        });
    </script>
</body>

</html>