<?php

/**
 * Approvals Management - Super Admin Approval Dashboard
 *
 * @package EduTrack
 * @subpackage Platform\Approvals
 * @version 1.0
 * @filepath public/platform/approvals/index.php
 *
 * v1.0 change (2026-10-08) [SWEEP X-1 + SIDEBAR + CSRF]:
 *   Platform-approvals sweep, X-1 in full, plus sidebar
 *   reconciliation, plus the CSRF field added to the two JS-built
 *   forms, plus two repairs.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 10 items across
 *   3 groups (Main: Dashboard, Tenants, Approvals, Users;
 *   Institution: Schools, Campuses; System: Audit Logs, Monitoring,
 *   Settings), brand heading "EduTrack", width 260px — is replaced
 *   by a single require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System),
 *   including an Approvals item in the Main group, brand heading
 *   "Student 360", plus the mobile toggle, the sidebar footer, and
 *   the toggleSidebar() JS.
 *
 *   $currentPage stays 'approvals' so the Audit Logs-equivalent
 *   Approvals item in the canonical partial is marked active on
 *   this page. $pendingApprovals = $totalPending is set before the
 *   partial so the sidebar badge matches the on-page total.
 *
 *   $projectRoot, $currentUser, $userFirstName, and $userAvatar are
 *   already set by this file. They are unchanged.
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
 *   applyFilters(), resetFilters(), approveRequest(),
 *   rejectRequest(), and the DOMContentLoaded handler are kept —
 *   they are page-scoped and do not conflict with the partial.
 *
 *   CSRF field. The two JS-built forms in approveRequest() and
 *   rejectRequest() now carry a hidden csrf_token input, matching
 *   the verify_csrf() call added to public/platform/approvals/
 *   approve.php v1.0. The token value is emitted by csrf_token()
 *   when it exists, or by Security::csrfToken() otherwise, matching
 *   the same function_exists()/class_exists() dispatch used in the
 *   handler.
 *
 *   Repair 1 — pending campuses. The "Pending Campuses" stat card
 *   previously always rendered 0 because $pendingCampuses was set
 *   once and never computed. A SELECT COUNT(*) FROM campus_requests
 *   WHERE status = 'pending' read is added, wrapped in the same
 *   try/catch shape as the school_requests pending count. If the
 *   campus_requests table is missing or the query throws, the count
 *   stays 0 and the page does not fatal. The $campusRequests
 *   variable remains declared but unread — reading it into a
 *   rendered campus list is a scope extension not applied here.
 *
 *   Repair 2 — inline schools count. The fourth stat card's total
 *   schools count was previously computed inside the HTML, outside
 *   every try/catch block. It is moved into the earlier try block
 *   and stored in $totalSchools so a missing schools column or a
 *   throwing query cannot take the whole page down.
 *
 *   The config.php require is unchanged. The auth guards are
 *   unchanged. The requests list, the filters, the empty state, the
 *   approve/reject buttons, the flash message rendering, and the
 *   entire CSS block outside the sidebar family are byte-identical
 *   to the file as pasted.
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

if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Approvals - Student 360 Platform';
$currentPage = 'approvals';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];

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
// CHECK TABLE AND COLUMNS
// =============================================
$tableExists = $db->getValue(
    "SELECT COUNT(*) FROM information_schema.tables 
     WHERE table_schema = ? AND table_name = 'school_requests'",
    [DB_NAME]
);

$hasDeletedAt = false;
$hasReviewNotes = false;

if ($tableExists > 0) {
    $columns = $db->fetchAll("SHOW COLUMNS FROM school_requests");
    $columnNames = array_column($columns, 'Field');
    $hasDeletedAt = in_array('deleted_at', $columnNames);
    $hasReviewNotes = in_array('review_notes', $columnNames);
}

// =============================================
// GET FILTERS
// =============================================
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'pending';
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// =============================================
// GET SCHOOL REQUESTS
// =============================================
$schoolRequests = [];
$campusRequests = [];

try {
    if ($tableExists > 0) {
        // Build the base SQL query
        $sql = "SELECT sr.*, 
                    t.tenant_name,
                    t.tenant_code,
                    u.first_name as requester_first_name,
                    u.last_name as requester_last_name,
                    u.email as requester_email,
                    u.username as requester_username
                FROM school_requests sr
                JOIN tenants t ON sr.tenant_id = t.id
                JOIN platform_users u ON sr.requested_by = u.id";

        $whereConditions = [];
        $params = [];

        // Add deleted_at condition only if column exists
        if ($hasDeletedAt) {
            $whereConditions[] = "(sr.deleted_at IS NULL OR sr.deleted_at = '')";
        }

        if ($statusFilter !== 'all' && !empty($statusFilter)) {
            $whereConditions[] = "sr.status = ?";
            $params[] = $statusFilter;
        }

        if (!empty($search)) {
            $whereConditions[] = "(sr.school_name LIKE ? OR sr.school_code LIKE ? OR t.tenant_name LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }

        $sql .= " ORDER BY 
                    CASE WHEN sr.status = 'pending' THEN 0 ELSE 1 END,
                    sr.requested_at DESC";

        $schoolRequests = $db->fetchAll($sql, $params);
    }
} catch (Exception $e) {
    error_log('School requests error: ' . $e->getMessage());
    $schoolRequests = [];
}

// =============================================
// GET COUNTS
// =============================================
$pendingSchools = 0;
$pendingCampuses = 0;
$totalPending = 0;
$totalSchools = 0;

try {
    if ($tableExists > 0) {
        $countSql = "SELECT COUNT(*) FROM school_requests WHERE status = 'pending'";
        if ($hasDeletedAt) {
            $countSql .= " AND (deleted_at IS NULL OR deleted_at = '')";
        }
        $pendingSchools = $db->getValue($countSql) ?? 0;
    }
} catch (Exception $e) {
    $pendingSchools = 0;
}

try {
    $campusTableExists = $db->getValue(
        "SELECT COUNT(*) FROM information_schema.tables 
         WHERE table_schema = ? AND table_name = 'campus_requests'",
        [DB_NAME]
    );
    if ($campusTableExists > 0) {
        $campusCols = $db->fetchAll("SHOW COLUMNS FROM campus_requests");
        $campusColNames = array_column($campusCols, 'Field');
        $campusHasDeletedAt = in_array('deleted_at', $campusColNames);

        $campusCountSql = "SELECT COUNT(*) FROM campus_requests WHERE status = 'pending'";
        if ($campusHasDeletedAt) {
            $campusCountSql .= " AND (deleted_at IS NULL OR deleted_at = '')";
        }
        $pendingCampuses = $db->getValue($campusCountSql) ?? 0;
    }
} catch (Exception $e) {
    error_log('Pending campuses count error: ' . $e->getMessage());
    $pendingCampuses = 0;
}

$totalPending = $pendingSchools + $pendingCampuses;

try {
    $totalSchools = $db->getValue("SELECT COUNT(*) FROM schools WHERE deleted_at IS NULL") ?? 0;
} catch (Exception $e) {
    error_log('Total schools count error: ' . $e->getMessage());
    $totalSchools = 0;
}

// =============================================
// GET FLASH MESSAGES
// =============================================
$successMessage = isset($_SESSION['approval_success']) ? $_SESSION['approval_success'] : '';
$errorMessage = isset($_SESSION['approval_error']) ? $_SESSION['approval_error'] : '';
unset($_SESSION['approval_success'], $_SESSION['approval_error']);

// =============================================
// STATUS OPTIONS
// =============================================
$statusOptions = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled'
];

$typeOptions = [
    'all' => 'All Types',
    'school' => 'Schools',
    'campus' => 'Campuses'
];

// =============================================
// CURRENT USER
// =============================================
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));

// Variables read by the platform sidebar partial
$pendingApprovals = $totalPending;

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

// CSRF token for the JS-built approve/reject forms
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

        .btn-danger {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-danger:hover {
            background: #c82333;
            color: #fff;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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

        .stat-card .stat-icon.orange {
            background: #ffe8d9;
            color: #fd7e14;
        }

        .stat-card .stat-icon.blue {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .stat-card .stat-icon.green {
            background: #d4edda;
            color: #28a745;
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

        .request-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 16px;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: all 0.3s;
        }

        .request-card:hover {
            border-color: #4facfe;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .request-card .request-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 8px;
        }

        .request-card .request-title {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .request-card .request-title i {
            margin-right: 8px;
        }

        .request-card .request-type {
            font-size: 12px;
            padding: 2px 12px;
            border-radius: 12px;
            font-weight: 500;
        }

        .request-card .request-type.school {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .request-card .request-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 13px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .request-card .request-meta i {
            width: 16px;
            margin-right: 4px;
        }

        .request-card .request-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
            margin: 8px 0 12px 0;
            font-size: 13px;
        }

        .request-card .request-details .detail-label {
            color: #6c757d;
            font-weight: 500;
        }

        .request-card .request-details .detail-value {
            color: #1a1a2e;
        }

        .request-card .request-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f0f2f5;
        }

        .request-card .request-actions .btn {
            font-size: 13px;
            padding: 6px 16px;
            border-radius: 8px;
        }

        .badge-status {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .badge-status.approved {
            background: #d4edda;
            color: #155724;
        }

        .badge-status.rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.cancelled {
            background: #e9ecef;
            color: #6c757d;
        }

        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 16px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            transition: all 0.3s ease;
            animation: slideDown 0.4s ease;
        }

        .alert-custom .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            width: 28px;
            text-align: center;
        }

        .alert-custom .alert-content {
            flex: 1;
        }

        .alert-custom .alert-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 2px;
        }

        .alert-custom .alert-message {
            font-size: 13px;
            opacity: 0.9;
        }

        .alert-custom .btn-close-custom {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            padding: 4px 8px;
            font-size: 18px;
            transition: opacity 0.2s;
            flex-shrink: 0;
            margin-top: -2px;
        }

        .alert-custom .btn-close-custom:hover {
            opacity: 1;
        }

        .alert-custom.alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 4px solid #28a745;
        }

        .alert-custom.alert-success .alert-icon {
            color: #28a745;
        }

        .alert-custom.alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-left: 4px solid #dc3545;
        }

        .alert-custom.alert-danger .alert-icon {
            color: #dc3545;
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

            .request-card .request-details {
                grid-template-columns: 1fr;
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

            .request-card .request-details {
                grid-template-columns: 1fr;
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

            .request-card {
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
                        <h1><i class="fas fa-check-double me-2"></i>Approvals</h1>
                        <p>Review and manage school and campus requests</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-outline-secondary" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-2"></i> Refresh
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer">
                    <?php if ($successMessage): ?>
                        <div class="alert-custom alert-success">
                            <div class="alert-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="alert-content">
                                <div class="alert-title">Success!</div>
                                <div class="alert-message"><?php echo htmlspecialchars($successMessage); ?></div>
                            </div>
                            <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($errorMessage): ?>
                        <div class="alert-custom alert-danger">
                            <div class="alert-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <div class="alert-content">
                                <div class="alert-title">Error!</div>
                                <div class="alert-message"><?php echo htmlspecialchars($errorMessage); ?></div>
                            </div>
                            <button class="btn-close-custom" onclick="this.closest('.alert-custom').remove()" aria-label="Close">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $totalPending; ?></div>
                            <div class="stat-label">Pending Requests</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $pendingSchools; ?></div>
                            <div class="stat-label">Pending Schools</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-map-marker-alt"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $pendingCampuses; ?></div>
                            <div class="stat-label">Pending Campuses</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="stat-number"><?php echo $totalSchools; ?></div>
                            <div class="stat-label">Total Schools</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <div class="filter-group search-group">
                        <label for="searchInput"><i class="fas fa-search"></i></label>
                        <input type="text" class="form-control" id="searchInput" name="search"
                            placeholder="Search requests..." value="<?php echo htmlspecialchars($search ?? ''); ?>" autocomplete="off">
                    </div>
                    <div class="filter-group">
                        <label for="statusFilter">Status</label>
                        <select class="form-select" id="statusFilter" name="status">
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="typeFilter">Type</label>
                        <select class="form-select" id="typeFilter" name="type">
                            <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="school" <?php echo $typeFilter === 'school' ? 'selected' : ''; ?>>Schools</option>
                            <option value="campus" <?php echo $typeFilter === 'campus' ? 'selected' : ''; ?>>Campuses</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="button" class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                    </div>
                </div>

                <!-- Requests Container -->
                <div id="requestsContainer">
                    <?php if (empty($schoolRequests)): ?>
                        <div class="card-custom" style="padding:60px 40px;text-align:center;">
                            <i class="fas fa-check-double" style="font-size:64px;color:#dee2e6;margin-bottom:20px;display:block;"></i>
                            <h4 style="color:#1a1a2e;margin-bottom:8px;">No Requests Found</h4>
                            <p style="color:#6c757d;font-size:14px;max-width:400px;margin:0 auto;">
                                <?php if ($statusFilter === 'pending'): ?>
                                    No pending requests to review. All caught up! 🎉
                                <?php else: ?>
                                    No requests match your filters.
                                <?php endif; ?>
                            </p>
                            <?php if ($statusFilter === 'pending'): ?>
                                <p class="mt-2">
                                    <a href="/platform/tenant/schools/request.php" class="btn btn-warning">
                                        <i class="fas fa-plus me-1"></i> Create Test Request
                                    </a>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($schoolRequests as $request): ?>
                            <div class="request-card">
                                <div class="request-header">
                                    <div class="request-title">
                                        <i class="fas fa-school"></i>
                                        <?php echo htmlspecialchars($request['school_name']); ?>
                                        <span class="badge-status <?php echo $request['status']; ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="request-type school">School</span>
                                        <span class="badge bg-secondary ms-2">ID: #<?php echo $request['id']; ?></span>
                                    </div>
                                </div>
                                <div class="request-meta">
                                    <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($request['tenant_name']); ?></span>
                                    <span><i class="fas fa-code"></i> <?php echo htmlspecialchars($request['school_code']); ?></span>
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($request['requester_first_name'] . ' ' . $request['requester_last_name']); ?></span>
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y H:i', strtotime($request['requested_at'])); ?></span>
                                </div>
                                <div class="request-details">
                                    <?php if (!empty($request['email'])): ?>
                                        <div><span class="detail-label">Email:</span> <span class="detail-value"><?php echo htmlspecialchars($request['email']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($request['phone'])): ?>
                                        <div><span class="detail-label">Phone:</span> <span class="detail-value"><?php echo htmlspecialchars($request['phone']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($request['address'])): ?>
                                        <div><span class="detail-label">Address:</span> <span class="detail-value"><?php echo htmlspecialchars($request['address']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($request['school_type'])): ?>
                                        <div><span class="detail-label">Type:</span> <span class="detail-value"><?php echo ucfirst($request['school_type']); ?></span></div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($request['status'] === 'pending'): ?>
                                    <div class="request-actions">
                                        <button class="btn btn-success" onclick="approveRequest(<?php echo $request['id']; ?>, 'school')">
                                            <i class="fas fa-check me-1"></i> Approve
                                        </button>
                                        <button class="btn btn-danger" onclick="rejectRequest(<?php echo $request['id']; ?>, 'school')">
                                            <i class="fas fa-times me-1"></i> Reject
                                        </button>
                                    </div>
                                <?php elseif ($request['status'] === 'approved' || $request['status'] === 'rejected'): ?>
                                    <div class="request-actions">
                                        <?php if (!empty($request['review_notes'])): ?>
                                            <div class="request-notes" style="background:#f8f9fa;border-radius:8px;padding:10px 14px;margin-top:8px;font-size:13px;color:#6c757d;">
                                                <i class="fas fa-comment me-1"></i>
                                                <strong>Review Notes:</strong> <?php echo htmlspecialchars($request['review_notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // CSRF token for the JS-built approve/reject forms.
        // Populated server-side from csrf_token() or Security::csrfToken().
        const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

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
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            let url = '/platform/approvals/index.php?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (status) url += 'status=' + encodeURIComponent(status) + '&';
            if (type) url += 'type=' + encodeURIComponent(type);
            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = '/platform/approvals/index.php';
        }

        function approveRequest(id, type) {
            if (confirm('Approve this ' + type + ' request?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/platform/approvals/approve.php';
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'request_id';
                idInput.value = id;
                form.appendChild(idInput);
                const typeInput = document.createElement('input');
                typeInput.type = 'hidden';
                typeInput.name = 'request_type';
                typeInput.value = type;
                form.appendChild(typeInput);
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve';
                form.appendChild(actionInput);
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = CSRF_TOKEN;
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function rejectRequest(id, type) {
            const reason = prompt('Please provide a reason for rejection:');
            if (reason !== null && reason.trim() !== '') {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '/platform/approvals/approve.php';
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'request_id';
                idInput.value = id;
                form.appendChild(idInput);
                const typeInput = document.createElement('input');
                typeInput.type = 'hidden';
                typeInput.name = 'request_type';
                typeInput.value = type;
                form.appendChild(typeInput);
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'reject';
                form.appendChild(actionInput);
                const reasonInput = document.createElement('input');
                reasonInput.type = 'hidden';
                reasonInput.name = 'rejection_reason';
                reasonInput.value = reason.trim();
                form.appendChild(reasonInput);
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = CSRF_TOKEN;
                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            } else if (reason !== null) {
                alert('Please provide a reason for rejection.');
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

            document.getElementById('statusFilter').addEventListener('change', function() {
                applyFilters();
            });

            document.getElementById('typeFilter').addEventListener('change', function() {
                applyFilters();
            });
        });
    </script>
</body>

</html>