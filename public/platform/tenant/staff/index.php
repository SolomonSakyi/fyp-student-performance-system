<?php

/**
 * Staff Management - List all staff members for a tenant
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Staff
 * @version 1.0
 * @filepath public/platform/tenant/staff/index.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Staff index file of the tenant-surface sweep. Three changes:
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. This file's inline
 *       sidebar carried six items (Dashboard; Schools, Campuses;
 *       Staff, Students; Settings) and no Subscription,
 *       Notifications, or Logout nav link. The partial carries
 *       all nine. After the refactor, this page renders the
 *       Subscription, Notifications, and Logout items as well.
 *       The inline sidebar's Settings link pointed at
 *       /platform/tenant/settings.php; the partial's Settings
 *       link points at /platform/tenant/settings/index.php —
 *       the live shell. The refactor corrects that link.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - The @version tag was unified to 1.0.
 *   The v2.1 AJAX autocomplete for the search box is preserved
 *   unchanged. Every other line of the file is byte-identical to
 *   v2.1. The @package tag remains 'EduTrack'.
 *
 * CHANGELOG v2.1
 * - Added live AJAX autocomplete for the search box (search by name)
 * - Shows matching staff names as user types
 * - Clicking a suggestion loads that staff member directly
 * - Pressing Enter still performs the normal full search
 * - Arrow key navigation + Escape to close dropdown supported
 */

// ==============================================
// SESSION & AUTHENTICATION
// ==============================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// ==============================================
// ORGANIZATIONAL CONTEXT
// ==============================================
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;
$campusId = $_SESSION['campus_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if (!$tenantId) {
    $_SESSION['errors'] = ['No tenant context found. Please select a tenant first.'];
    header('Location: /platform/tenants/select.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$pageTitle = 'Staff Management - Student 360 Platform';
$currentPage = 'staff';

// ==============================================
// DATABASE & CONFIGURATION
// ==============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ==============================================
// AJAX ENDPOINT — Live staff name autocomplete
// ==============================================
if (isset($_GET['ajax_search_staff']) && $_GET['ajax_search_staff'] == '1') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || mb_strlen($q) < 1) {
        echo json_encode(['results' => []]);
        exit;
    }
    try {
        $like = '%' . $q . '%';
        $rows = $db->fetchAll(
            "SELECT s.id,
                    s.staff_number,
                    s.job_title,
                    p.first_name,
                    p.middle_name,
                    p.last_name,
                    p.preferred_name,
                    p.email,
                    p.profile_photo_url
             FROM staff s
             JOIN persons p ON s.person_id = p.id
             WHERE s.tenant_id = ?
               AND s.deleted_at IS NULL
               AND (
                    p.first_name LIKE ?
                 OR p.middle_name LIKE ?
                 OR p.last_name LIKE ?
                 OR p.preferred_name LIKE ?
                 OR CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) LIKE ?
                 OR CONCAT_WS(' ', p.first_name, p.last_name) LIKE ?
                 OR p.email LIKE ?
                 OR s.staff_number LIKE ?
               )
             ORDER BY p.first_name ASC, p.last_name ASC
             LIMIT 10",
            [$tenantId, $like, $like, $like, $like, $like, $like, $like, $like]
        );

        $results = [];
        foreach ($rows as $r) {
            $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $fullName = preg_replace('/\s+/', ' ', $fullName);
            $displayName = !empty($r['preferred_name']) ? $r['preferred_name'] : $fullName;
            $initials = strtoupper(
                mb_substr($r['first_name'] ?? '', 0, 1) . mb_substr($r['last_name'] ?? '', 0, 1)
            );
            $results[] = [
                'id' => (int)$r['id'],
                'name' => $displayName,
                'full_name' => $fullName,
                'initials' => $initials,
                'staff_number' => $r['staff_number'] ?? '',
                'job_title' => $r['job_title'] ?? '',
                'email' => $r['email'] ?? '',
                'photo' => $r['profile_photo_url'] ?? ''
            ];
        }
        echo json_encode(['results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['results' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ==============================================
// GET FILTERS FROM URL
// ==============================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$department = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$employmentType = isset($_GET['employment_type']) ? (int)$_GET['employment_type'] : 0;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($page < 1) $page = 1;
if ($limit < 1 || $limit > 200) $limit = 20;
$offset = ($page - 1) * $limit;

// ==============================================
// BUILD QUERY WITH FILTERS
// ==============================================
$where = ["s.tenant_id = ?", "s.deleted_at IS NULL"];
$params = [$tenantId];

if (!empty($search)) {
    $searchTerm = "%$search%";
    $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ? OR s.staff_number LIKE ? OR s.job_title LIKE ?)";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($status)) {
    $where[] = "s.is_active = ?";
    $params[] = ($status === 'active') ? 1 : 0;
}

if ($category > 0) {
    $where[] = "s.staff_category_id = ?";
    $params[] = $category;
}

if ($department > 0) {
    $where[] = "s.department_id = ?";
    $params[] = $department;
}

if ($employmentType > 0) {
    $where[] = "s.employment_type_id = ?";
    $params[] = $employmentType;
}

$whereClause = implode(" AND ", $where);

// ==============================================
// GET STAFF LIST WITH PAGINATION
// ==============================================

// Count total
$countSql = "SELECT COUNT(*) as total 
             FROM staff s
             JOIN persons p ON s.person_id = p.id
             WHERE $whereClause";
$countResult = $db->fetchOne($countSql, $params);
$totalRecords = (int)($countResult['total'] ?? 0);
$totalPages = $totalRecords > 0 ? ceil($totalRecords / $limit) : 0;

// Get staff with all details
$sql = "SELECT 
            s.id,
            s.uuid,
            s.staff_number,
            s.job_title,
            s.hire_date,
            s.is_teaching_staff,
            s.is_active,
            s.created_at,
            p.id as person_id,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.preferred_name,
            p.display_name,
            p.email,
            p.primary_phone,
            p.gender,
            p.profile_photo_url,
            p.date_of_birth,
            sc.category_name as staff_category,
            st.type_name as staff_type,
            et.employment_type_name as employment_type,
            ss.status_name as staff_status,
            d.department_name as department,
            (SELECT COUNT(*) FROM staff_academic_assignments saa WHERE saa.staff_id = s.id AND saa.is_active = 1) as assignment_count,
            (SELECT COUNT(*) FROM staff_emergency_contacts sec WHERE sec.staff_id = s.id) as emergency_contact_count
        FROM staff s
        JOIN persons p ON s.person_id = p.id
        LEFT JOIN staff_categories sc ON s.staff_category_id = sc.id
        LEFT JOIN staff_types st ON s.staff_type_id = st.id
        LEFT JOIN employment_types et ON s.employment_type_id = et.id
        LEFT JOIN staff_statuses ss ON s.staff_status_id = ss.id
        LEFT JOIN departments d ON s.department_id = d.id
        WHERE $whereClause
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$staffList = $db->fetchAll($sql, $params);

// ==============================================
// GET FILTER OPTIONS
// ==============================================
$categories = $db->fetchAll(
    "SELECT id, category_name FROM staff_categories WHERE is_active = 1 ORDER BY sort_order"
);

$departments = $db->fetchAll(
    "SELECT id, department_name FROM departments WHERE school_id = ? AND is_active = 1 ORDER BY department_name",
    [$schoolId]
);

$employmentTypes = $db->fetchAll(
    "SELECT id, employment_type_name FROM employment_types WHERE is_active = 1 ORDER BY employment_type_name"
);

$statuses = [
    ['value' => 'active', 'label' => 'Active'],
    ['value' => 'inactive', 'label' => 'Inactive']
];

// ==============================================
// GET TENANT NAME
// ==============================================
$tenantName = '';
try {
    $tenant = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($tenant) {
        $tenantName = $tenant['tenant_name'] ?? 'Tenant #' . $tenantId;
    }
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

// ==============================================
// GET SCHOOL NAME
// ==============================================
$schoolName = '';
if ($schoolId > 0) {
    try {
        $school = $db->fetchOne("SELECT school_name FROM schools WHERE id = ? AND deleted_at IS NULL", [$schoolId]);
        if ($school) {
            $schoolName = $school['school_name'] ?? '';
        }
    } catch (Exception $e) {
        $schoolName = '';
    }
}

// ==============================================
// GET STATISTICS
// ==============================================
$totalStaff = $db->getValue("SELECT COUNT(*) FROM staff s WHERE s.tenant_id = ? AND s.deleted_at IS NULL", [$tenantId]) ?? 0;
$activeStaff = $db->getValue("SELECT COUNT(*) FROM staff s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.is_active = 1", [$tenantId]) ?? 0;
$inactiveStaff = $db->getValue("SELECT COUNT(*) FROM staff s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.is_active = 0", [$tenantId]) ?? 0;
$teachingStaff = $db->getValue("SELECT COUNT(*) FROM staff s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.is_teaching_staff = 1", [$tenantId]) ?? 0;
$nonTeachingStaff = $db->getValue("SELECT COUNT(*) FROM staff s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.is_teaching_staff = 0", [$tenantId]) ?? 0;
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
        *,
        *::before,
        *::after {
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

        .tenant-banner {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tenant-banner .tenant-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-banner .tenant-info i {
            font-size: 24px;
            color: #4facfe;
        }

        .tenant-banner .tenant-info .tenant-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-banner .tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

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

        .stat-card .stat-icon.orange {
            background: #ffe8d9;
            color: #fd7e14;
        }

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .stat-card .stat-icon.teal {
            background: #d0f0f0;
            color: #20c997;
        }

        .stat-card .stat-icon.pink {
            background: #fce4ec;
            color: #e83e8c;
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

        .filters-bar {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .filters-bar .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 180px;
        }

        .filters-bar .filter-group label {
            font-weight: 500;
            font-size: 12px;
            color: #1a1a2e;
            white-space: nowrap;
        }

        .filters-bar .filter-group .form-control,
        .filters-bar .filter-group .form-select {
            height: 38px;
            font-size: 13px;
            border-radius: 8px;
            border: 1.5px solid #e9ecef;
        }

        .filters-bar .filter-group .form-control {
            flex: 1;
            min-width: 150px;
        }

        .filters-bar .filter-group .form-select {
            min-width: 140px;
        }

        /* ============================================
           AUTOCOMPLETE DROPDOWN
           ============================================ */
        .search-autocomplete-wrap {
            position: relative;
            flex: 1;
            min-width: 220px;
        }

        .search-autocomplete-wrap .form-control {
            width: 100%;
            padding-right: 36px;
        }

        .search-autocomplete-wrap .search-spinner {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            color: #4facfe;
            font-size: 13px;
            display: none;
        }

        .search-autocomplete-wrap.loading .search-spinner {
            display: block;
        }

        .search-autocomplete-wrap .clear-search {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #adb5bd;
            cursor: pointer;
            font-size: 13px;
            padding: 2px 4px;
            display: none;
            border-radius: 4px;
        }

        .search-autocomplete-wrap .clear-search:hover {
            color: #495057;
            background: #f1f3f5;
        }

        .search-autocomplete-wrap.has-value .clear-search {
            display: block;
        }

        .search-autocomplete-wrap.has-value .search-spinner {
            display: none;
        }

        .autocomplete-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
            max-height: 380px;
            overflow-y: auto;
            z-index: 2000;
            display: none;
            padding: 6px;
            min-width: 320px;
        }

        .autocomplete-dropdown.show {
            display: block;
            animation: acDropIn 0.15s ease-out;
        }

        @keyframes acDropIn {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .autocomplete-dropdown .ac-header {
            font-size: 10px;
            font-weight: 700;
            color: #adb5bd;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 8px 12px 4px;
        }

        .autocomplete-dropdown .ac-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 12px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            transition: background 0.15s ease;
            outline: none;
        }

        .autocomplete-dropdown .ac-item:hover,
        .autocomplete-dropdown .ac-item.active {
            background: #f0f7ff;
        }

        .autocomplete-dropdown .ac-item.active {
            background: linear-gradient(135deg, #e3f0ff 0%, #e8f5ff 100%);
            box-shadow: inset 3px 0 0 #4facfe;
        }

        .autocomplete-dropdown .ac-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
            border: 2px solid #e3f0ff;
        }

        .autocomplete-dropdown .ac-avatar-text {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            color: #fff;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            border: 2px solid #e3f0ff;
        }

        .autocomplete-dropdown .ac-body {
            flex: 1;
            min-width: 0;
        }

        .autocomplete-dropdown .ac-name {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .autocomplete-dropdown .ac-name mark {
            background: #fff3bf;
            color: #1a1a2e;
            padding: 0 1px;
            border-radius: 2px;
        }

        .autocomplete-dropdown .ac-meta {
            font-size: 11px;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .autocomplete-dropdown .ac-meta i {
            font-size: 10px;
            margin-right: 3px;
        }

        .autocomplete-dropdown .ac-badge {
            font-size: 10px;
            background: #e9ecef;
            color: #495057;
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 500;
            margin-left: 6px;
        }

        .autocomplete-dropdown .ac-empty {
            padding: 20px 16px;
            text-align: center;
            color: #adb5bd;
            font-size: 13px;
        }

        .autocomplete-dropdown .ac-empty i {
            display: block;
            font-size: 22px;
            margin-bottom: 6px;
            opacity: 0.5;
        }

        .autocomplete-dropdown .ac-footer {
            padding: 6px 12px;
            border-top: 1px solid #f0f2f5;
            margin-top: 4px;
            font-size: 11px;
            color: #adb5bd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .autocomplete-dropdown .ac-footer kbd {
            background: #f1f3f5;
            color: #495057;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 10px;
            font-family: inherit;
            border: 1px solid #dee2e6;
        }

        /* ============================================ */

        .table-container {
            background: #fff;
            border-radius: 14px;
            padding: 0;
            border: 1px solid rgba(0, 0, 0, 0.03);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            width: 100%;
        }

        .table-container .table {
            margin: 0;
            font-size: 13px;
            width: 100%;
        }

        .table-container .table thead th {
            background: #f8f9fa;
            padding: 12px 16px;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
        }

        .table-container .table tbody td {
            padding: 10px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }

        .table-container .table tbody tr:hover {
            background: #f8f9fa;
        }

        .table-container .table tbody tr:last-child td {
            border-bottom: none;
        }

        .staff-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: #e9ecef;
            border: 2px solid #e9ecef;
        }

        .staff-avatar-text {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: #fff;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            flex-shrink: 0;
        }

        .staff-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .staff-number {
            font-size: 12px;
            color: #6c757d;
        }

        .staff-job-title {
            font-size: 12px;
            color: #6c757d;
        }

        .badge-status {
            padding: 3px 12px;
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
            background: #f8d7da;
            color: #721c24;
        }

        .badge-status.teaching {
            background: #cce5ff;
            color: #004085;
        }

        .badge-status.non-teaching {
            background: #e9ecef;
            color: #6c757d;
        }

        .table-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .table-actions .btn {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 6px;
            line-height: 1.4;
        }

        .table-actions .btn i {
            margin-right: 2px;
        }

        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pagination-wrapper .info {
            color: #6c757d;
            font-size: 13px;
        }

        .pagination {
            margin: 0;
        }

        .pagination .page-link {
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
            color: #1a1a2e;
        }

        .pagination .page-item.active .page-link {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-color: #4facfe;
            color: #fff;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            display: block;
            margin-bottom: 16px;
        }

        .empty-state h5 {
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 8px;
        }

        @media (max-width: 1200px) {
            .stats-row {
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

            .tenant-banner {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card {
                padding: 12px 14px;
            }

            .stat-card .stat-number {
                font-size: 18px;
            }

            .stat-card .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filters-bar .filter-group {
                width: 100%;
                flex-wrap: wrap;
            }

            .filters-bar .filter-group label {
                width: 100%;
            }

            .search-autocomplete-wrap {
                min-width: 100%;
            }

            .autocomplete-dropdown {
                min-width: 100%;
            }

            .table-container .table {
                font-size: 12px;
            }

            .table-container .table thead th {
                padding: 8px 10px;
                font-size: 10px;
            }

            .table-container .table tbody td {
                padding: 8px 10px;
            }

            .table-actions .btn {
                font-size: 11px;
                padding: 2px 6px;
            }

            .pagination-wrapper {
                flex-direction: column;
                align-items: center;
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
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .stat-card {
                padding: 10px 12px;
            }

            .stat-card .stat-number {
                font-size: 16px;
            }

            .stat-card .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .stat-card .stat-label {
                font-size: 10px;
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
                        <h1><i class="fas fa-user-tie me-2"></i>Staff Management</h1>
                        <p>Manage all staff members across your institution</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/staff/create.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Staff
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo htmlspecialchars($tenantName); ?></div>
                            <?php if (!empty($schoolName)): ?>
                                <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                    <i class="fas fa-school me-1"></i> <?php echo htmlspecialchars($schoolName); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tenant-badge"><i class="fas fa-check-circle me-1"></i> Data Isolation Active</div>
                </div>

                <!-- Stats -->
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalStaff); ?></div>
                            <div class="stat-label">Total Staff</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($activeStaff); ?></div>
                            <div class="stat-label">Active Staff</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-user-slash"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($inactiveStaff); ?></div>
                            <div class="stat-label">Inactive Staff</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($teachingStaff); ?></div>
                            <div class="stat-label">Teaching Staff</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon teal"><i class="fas fa-briefcase"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($nonTeachingStaff); ?></div>
                            <div class="stat-label">Non-Teaching Staff</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon pink"><i class="fas fa-user-plus"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalStaff > 0 ? round($teachingStaff / $totalStaff * 100) : 0); ?>%</div>
                            <div class="stat-label">Teaching Ratio</div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="filters-bar">
                    <div class="filter-group" style="flex:1.5;">
                        <label><i class="fas fa-search me-1"></i> Search</label>
                        <div class="search-autocomplete-wrap" id="searchWrap">
                            <input type="text"
                                class="form-control"
                                id="searchInput"
                                placeholder="Start typing a name..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                autocomplete="off"
                                spellcheck="false"
                                aria-autocomplete="list"
                                aria-controls="autocompleteDropdown"
                                aria-expanded="false">
                            <i class="fas fa-spinner fa-spin search-spinner"></i>
                            <button type="button" class="clear-search" id="clearSearchBtn" title="Clear search" aria-label="Clear search">
                                <i class="fas fa-times-circle"></i>
                            </button>
                            <div class="autocomplete-dropdown" id="autocompleteDropdown" role="listbox">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select class="form-select" id="statusFilter" onchange="applyFilters()">
                            <option value="">All</option>
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?php echo $s['value']; ?>" <?php echo $status === $s['value'] ? 'selected' : ''; ?>><?php echo $s['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Category</label>
                        <select class="form-select" id="categoryFilter" onchange="applyFilters()">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category === (int)$cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Department</label>
                        <select class="form-select" id="departmentFilter" onchange="applyFilters()">
                            <option value="0">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $department === (int)$dept['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['department_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                            <i class="fas fa-times me-1"></i> Clear
                        </button>
                        <button class="btn btn-primary btn-sm" onclick="applyFilters()">
                            <i class="fas fa-search me-1"></i> Apply
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th>Staff #</th>
                                <th>Job Title</th>
                                <th>Category</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th style="text-align:center;min-width:180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="staffTableBody">
                            <?php if (empty($staffList)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="fas fa-user-tie"></i>
                                        <h5>No Staff Found</h5>
                                        <p>No staff members match your search criteria. Try adjusting your filters or <a href="/platform/tenant/staff/create.php">add a new staff member</a>.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($staffList as $staff): ?>
                                    <?php
                                    $fullName = trim($staff['first_name'] . ' ' . ($staff['middle_name'] ?? '') . ' ' . $staff['last_name']);
                                    $displayName = $staff['preferred_name'] ?: $fullName;
                                    $photoUrl = $staff['profile_photo_url'] ?? '';
                                    $initials = strtoupper(substr($staff['first_name'] ?? 'S', 0, 1) . substr($staff['last_name'] ?? 'T', 0, 1));
                                    $statusClass = $staff['is_active'] == 1 ? 'active' : 'inactive';
                                    $teachingClass = $staff['is_teaching_staff'] == 1 ? 'teaching' : 'non-teaching';
                                    $teachingLabel = $staff['is_teaching_staff'] == 1 ? 'Teaching' : 'Non-Teaching';
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <?php if (!empty($photoUrl)): ?>
                                                    <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="<?php echo htmlspecialchars($displayName); ?>" class="staff-avatar">
                                                <?php else: ?>
                                                    <div class="staff-avatar-text"><?php echo $initials; ?></div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="staff-name"><?php echo htmlspecialchars($displayName); ?></div>
                                                    <div class="staff-number"><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($staff['email'] ?? 'N/A'); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars($staff['staff_number'] ?? 'N/A'); ?></div>
                                            <div style="font-size:11px;color:#6c757d;"><?php echo date('M d, Y', strtotime($staff['hire_date'] ?? 'now')); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($staff['job_title'] ?? 'N/A'); ?></div>
                                            <span class="badge-status <?php echo $teachingClass; ?>" style="font-size:10px;"><?php echo $teachingLabel; ?></span>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($staff['staff_category'] ?? 'N/A'); ?></div>
                                            <div style="font-size:11px;color:#6c757d;"><?php echo htmlspecialchars($staff['staff_type'] ?? ''); ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($staff['department'])): ?>
                                                <?php echo htmlspecialchars($staff['department']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status <?php echo $statusClass; ?>"><?php echo $staff['is_active'] == 1 ? 'Active' : 'Inactive'; ?></span>
                                            <?php if ($staff['is_teaching_staff'] == 1): ?>
                                                <div style="font-size:10px;color:#6c757d;margin-top:2px;">
                                                    <i class="fas fa-book me-1"></i> <?php echo $staff['assignment_count'] ?? 0; ?> assignments
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <a href="/platform/tenant/staff/view.php?id=<?php echo $staff['id']; ?>" class="btn btn-sm btn-outline-info" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="/platform/tenant/staff/edit.php?id=<?php echo $staff['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($staff['is_active'] == 1): ?>
                                                    <button class="btn btn-sm btn-outline-warning" onclick="toggleStatus(<?php echo $staff['id']; ?>, 0)" title="Deactivate">
                                                        <i class="fas fa-pause"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-success" onclick="toggleStatus(<?php echo $staff['id']; ?>, 1)" title="Activate">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteStaff(<?php echo $staff['id']; ?>)" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="pagination-wrapper">
                        <div class="info">Showing <?php echo min($offset + 1, $totalRecords); ?> to <?php echo min($offset + $limit, $totalRecords); ?> of <?php echo number_format($totalRecords); ?> staff</div>
                        <nav>
                            <ul class="pagination" id="paginationControls">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="changePage(1);return false;">First</a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="changePage(<?php echo $page - 1; ?>);return false;">Prev</a>
                                </li>
                                <?php
                                $startPage = max(1, $page - 3);
                                $endPage = min($totalPages, $page + 3);
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="#" onclick="changePage(<?php echo $i; ?>);return false;"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="changePage(<?php echo $page + 1; ?>);return false;">Next</a>
                                </li>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="changePage(<?php echo $totalPages; ?>);return false;">Last</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================================
        // TOGGLE SIDEBAR
        // ============================================================
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
                window.location.href = '/platform/tenant/logout.php';
            }
        }

        // ============================================================
        // FILTERS
        // ============================================================
        function getFilters() {
            return {
                search: document.getElementById('searchInput').value.trim(),
                status: document.getElementById('statusFilter').value,
                category: document.getElementById('categoryFilter').value,
                department: document.getElementById('departmentFilter').value
            };
        }

        function applyFilters() {
            const filters = getFilters();
            let url = window.location.pathname + '?';
            if (filters.search) url += 'search=' + encodeURIComponent(filters.search) + '&';
            if (filters.status) url += 'status=' + encodeURIComponent(filters.status) + '&';
            if (filters.category && filters.category !== '0') url += 'category=' + encodeURIComponent(filters.category) + '&';
            if (filters.department && filters.department !== '0') url += 'department=' + encodeURIComponent(filters.department) + '&';
            url += 'page=1';
            window.location.href = url;
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('categoryFilter').value = '0';
            document.getElementById('departmentFilter').value = '0';
            applyFilters();
        }

        function changePage(page) {
            const filters = getFilters();
            let url = window.location.pathname + '?page=' + page;
            if (filters.search) url += '&search=' + encodeURIComponent(filters.search);
            if (filters.status) url += '&status=' + encodeURIComponent(filters.status);
            if (filters.category && filters.category !== '0') url += '&category=' + encodeURIComponent(filters.category);
            if (filters.department && filters.department !== '0') url += '&department=' + encodeURIComponent(filters.department);
            window.location.href = url;
        }

        // ============================================================
        // LIVE SEARCH AUTOCOMPLETE
        // ------------------------------------------------------------
        // Behaviour:
        //  - As soon as 1 character is typed, fetch matching staff
        //  - Show dropdown with avatar, name (highlighted), staff #, job title
        //  - Click a suggestion -> go to that staff member's profile
        //  - Arrow keys to navigate, Enter to open active suggestion
        //  - Enter (with no active suggestion) -> normal full search
        //  - Escape -> close dropdown
        //  - Click outside -> close dropdown
        //  - 250ms debounce to avoid hammering the server
        // ============================================================
        (function() {
            const searchInput = document.getElementById('searchInput');
            const searchWrap = document.getElementById('searchWrap');
            const dropdown = document.getElementById('autocompleteDropdown');
            const clearBtn = document.getElementById('clearSearchBtn');
            if (!searchInput || !dropdown) return;

            let debounceTimer = null;
            let currentRequestId = 0;
            let activeIndex = -1;
            let currentResults = [];

            function escapeHtml(str) {
                if (str === null || str === undefined) return '';
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function escapeRegex(str) {
                return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function highlight(text, query) {
                const safe = escapeHtml(text || '');
                if (!query) return safe;
                try {
                    const re = new RegExp('(' + escapeRegex(query) + ')', 'ig');
                    return safe.replace(re, '<mark>$1</mark>');
                } catch (e) {
                    return safe;
                }
            }

            function updateClearButton() {
                if (searchInput.value.trim() !== '') {
                    searchWrap.classList.add('has-value');
                } else {
                    searchWrap.classList.remove('has-value');
                }
            }

            function hideDropdown() {
                dropdown.classList.remove('show');
                searchInput.setAttribute('aria-expanded', 'false');
                activeIndex = -1;
            }

            function showDropdown() {
                dropdown.classList.add('show');
                searchInput.setAttribute('aria-expanded', 'true');
            }

            function setActiveIndex(idx) {
                const items = dropdown.querySelectorAll('.ac-item');
                items.forEach(el => el.classList.remove('active'));
                activeIndex = idx;
                if (idx >= 0 && items[idx]) {
                    items[idx].classList.add('active');
                    items[idx].scrollIntoView({
                        block: 'nearest'
                    });
                }
            }

            function renderResults(results, query) {
                currentResults = results;
                activeIndex = -1;

                if (!results.length) {
                    dropdown.innerHTML = `
                        <div class="ac-empty">
                            <i class="fas fa-user-slash"></i>
                            No staff found matching "<strong>${escapeHtml(query)}</strong>"
                        </div>
                        <div class="ac-footer">
                            <span>Press <kbd>Enter</kbd> to run a full search</span>
                        </div>`;
                    showDropdown();
                    return;
                }

                let html = '<div class="ac-header">Staff Suggestions</div>';
                results.forEach((r, i) => {
                    const avatarHtml = r.photo ?
                        `<img src="${escapeHtml(r.photo)}" alt="" class="ac-avatar" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                           <div class="ac-avatar-text" style="display:none;">${escapeHtml(r.initials)}</div>` :
                        `<div class="ac-avatar-text">${escapeHtml(r.initials)}</div>`;

                    const metaParts = [];
                    if (r.staff_number) metaParts.push(`<i class="fas fa-id-badge"></i>${escapeHtml(r.staff_number)}`);
                    if (r.job_title) metaParts.push(`<i class="fas fa-briefcase"></i>${escapeHtml(r.job_title)}`);

                    html += `
                        <a href="/platform/tenant/staff/view.php?id=${r.id}"
                           class="ac-item"
                           data-index="${i}"
                           role="option"
                           aria-selected="false">
                            ${avatarHtml}
                            <div class="ac-body">
                                <div class="ac-name">${highlight(r.name, query)}</div>
                                <div class="ac-meta">${metaParts.join(' &nbsp;·&nbsp; ')}</div>
                            </div>
                        </a>`;
                });
                html += `
                    <div class="ac-footer">
                        <span><kbd>↑</kbd> <kbd>↓</kbd> to navigate · <kbd>Enter</kbd> to open · <kbd>Esc</kbd> to close</span>
                    </div>`;

                dropdown.innerHTML = html;
                showDropdown();
            }

            function fetchResults(query) {
                const requestId = ++currentRequestId;
                searchWrap.classList.add('loading');
                fetch('/platform/tenant/staff/index.php?ajax_search_staff=1&q=' + encodeURIComponent(query), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (requestId !== currentRequestId) return; // stale
                        searchWrap.classList.remove('loading');
                        const results = (data && data.results) ? data.results : [];
                        renderResults(results, query);
                    })
                    .catch(() => {
                        if (requestId !== currentRequestId) return;
                        searchWrap.classList.remove('loading');
                        hideDropdown();
                    });
            }

            function scheduleFetch() {
                const q = searchInput.value.trim();
                updateClearButton();

                if (debounceTimer) clearTimeout(debounceTimer);

                if (q.length < 1) {
                    hideDropdown();
                    return;
                }

                debounceTimer = setTimeout(() => {
                    fetchResults(q);
                }, 250);
            }

            // -------- Event Listeners --------
            searchInput.addEventListener('input', scheduleFetch);

            searchInput.addEventListener('focus', function() {
                updateClearButton();
                if (searchInput.value.trim().length >= 1 && currentResults.length > 0) {
                    showDropdown();
                }
            });

            searchInput.addEventListener('keydown', function(e) {
                const items = dropdown.querySelectorAll('.ac-item');
                const dropdownOpen = dropdown.classList.contains('show');

                if (e.key === 'ArrowDown') {
                    if (!dropdownOpen && searchInput.value.trim().length >= 1) {
                        scheduleFetch();
                        e.preventDefault();
                        return;
                    }
                    if (items.length === 0) return;
                    e.preventDefault();
                    const next = (activeIndex + 1) % items.length;
                    setActiveIndex(next);
                } else if (e.key === 'ArrowUp') {
                    if (!dropdownOpen || items.length === 0) return;
                    e.preventDefault();
                    const prev = (activeIndex - 1 + items.length) % items.length;
                    setActiveIndex(prev);
                } else if (e.key === 'Enter') {
                    if (dropdownOpen && activeIndex >= 0 && items[activeIndex]) {
                        e.preventDefault();
                        const href = items[activeIndex].getAttribute('href');
                        if (href) window.location.href = href;
                        return;
                    }
                    // Otherwise: normal search behaviour (form apply)
                    e.preventDefault();
                    hideDropdown();
                    applyFilters();
                } else if (e.key === 'Escape') {
                    hideDropdown();
                    searchInput.blur();
                }
            });

            // Clicking a suggestion
            dropdown.addEventListener('click', function(e) {
                const item = e.target.closest('.ac-item');
                if (item) {
                    const href = item.getAttribute('href');
                    if (href) window.location.href = href;
                }
            });

            // Prevent blur when clicking inside the dropdown
            dropdown.addEventListener('mousedown', function(e) {
                e.preventDefault();
            });

            // Clear button
            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    searchInput.value = '';
                    updateClearButton();
                    hideDropdown();
                    currentResults = [];
                    searchInput.focus();
                });
            }

            // Click outside to close
            document.addEventListener('click', function(e) {
                if (!searchWrap.contains(e.target)) {
                    hideDropdown();
                }
            });

            // Initial state
            updateClearButton();
        })();

        // ============================================================
        // STAFF ACTIONS
        // ============================================================
        function toggleStatus(id, status) {
            const action = status == 1 ? 'activate' : 'deactivate';
            if (!confirm('Are you sure you want to ' + action + ' this staff member?')) return;

            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            fetch('/api/platform/staff/update_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        is_active: status
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (result.message || 'Failed to update status'));
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }

        function deleteStaff(id) {
            if (!confirm('Are you sure you want to delete this staff member? This action cannot be undone.')) return;
            if (!confirm('Really? This will permanently delete all associated data.')) return;

            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            fetch('/api/platform/staff/delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (result.message || 'Failed to delete staff'));
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    alert('Error: ' + error.message);
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }

        // ============================================================
        // LOAD USER INFO
        // ============================================================
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