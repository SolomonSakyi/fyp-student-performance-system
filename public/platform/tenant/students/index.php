<?php

/**
 * Student Management - List all students for a tenant
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Students
 * @version 1.0
 * @filepath public/platform/tenant/students/index.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Students index file of the students-surface sweep. Three
 *   changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. The partial carries the
 *       top-level items and — when $currentPage is 'students' —
 *       the student sub-menu (Biometric Registration, Biometric
 *       Devices, Device Users, Biometric Events, Attendance,
 *       Attendance Setup, Behaviour, Discipline, Financial
 *       Records). This file's inline sidebar carried the same
 *       items; the partial's version is now the single source.
 *     - The @version tag was unified to 1.0.
 *   The CSS block that styles .nav-sub and .nav-sub .nav-link is
 *   preserved in this file's <style> so the partial's sub-menu
 *   renders with the same indentation and active-state styling
 *   the inline sidebar carried. Every other line of the file is
 *   byte-identical to the previous version (1.4). The @package
 *   tag remains 'EduTrack'.
 *
 * Uses ONLY the columns that exist on `students`:
 *   id, uuid, tenant_id, school_id, campus_id, class_id,
 *   student_number, first_name, middle_name, last_name, date_of_birth,
 *   gender, nationality, religion, primary_phone, secondary_phone, email,
 *   address, town_city, district, region, enrollment_date, enrollment_status,
 *   guardian_name, guardian_phone, guardian_alt_phone, guardian_relationship,
 *   guardian_email, guardian_address, is_active, created_by, created_at,
 *   updated_at, deleted_at
 *
 * No reference to: persons, sections, class_sections, enrollments.
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

$tenantId     = $_SESSION['tenant_id'] ?? 0;
$schoolId     = $_SESSION['school_id'] ?? 0;
$campusId     = $_SESSION['campus_id'] ?? 0;
$userId       = $_SESSION['user_id'] ?? 0;
$currentUser  = $_SESSION['user_name'] ?? 'Admin';
$userAvatar   = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if (!$tenantId) {
    $_SESSION['errors'] = ['No tenant context found. Please select a tenant first.'];
    header('Location: /platform/tenants/select.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$pageTitle   = 'Student Management - Student 360 Platform';
$currentPage = 'students';

// ==============================================
// DATABASE
// ==============================================
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// ==============================================
// AJAX — student autocomplete
// ==============================================
if (isset($_GET['ajax_search_students']) && $_GET['ajax_search_students'] == '1') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['results' => []]);
        exit;
    }
    try {
        $like = '%' . $q . '%';
        $rows = $db->fetchAll(
            "SELECT s.id, s.student_number, s.first_name, s.middle_name, s.last_name,
                    s.email, c.class_name
             FROM students s
             LEFT JOIN classes c ON s.class_id = c.id
             WHERE s.tenant_id = ?
               AND s.deleted_at IS NULL
               AND (
                    s.first_name LIKE ?
                 OR s.middle_name LIKE ?
                 OR s.last_name LIKE ?
                 OR CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name) LIKE ?
                 OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?
                 OR s.email LIKE ?
                 OR s.student_number LIKE ?
               )
             ORDER BY s.first_name ASC, s.last_name ASC
             LIMIT 10",
            [$tenantId, $like, $like, $like, $like, $like, $like, $like]
        );

        $results = [];
        foreach ($rows as $r) {
            $full = trim(preg_replace(
                '/\s+/',
                ' ',
                ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? '')
            ));
            $display = $full !== '' ? $full : ('Student #' . $r['id']);
            $initials = strtoupper(
                mb_substr($r['first_name'] ?? '', 0, 1) . mb_substr($r['last_name'] ?? '', 0, 1)
            );
            if ($initials === '') $initials = 'ST';
            $results[] = [
                'id'             => (int)$r['id'],
                'name'           => $display,
                'initials'       => $initials,
                'student_number' => $r['student_number'] ?? '',
                'class_name'     => $r['class_name'] ?? '',
                'email'          => $r['email'] ?? '',
            ];
        }
        echo json_encode(['results' => $results]);
    } catch (Exception $e) {
        echo json_encode(['results' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ==============================================
// FILTERS
// ==============================================
$search  = isset($_GET['search'])   ? trim($_GET['search']) : '';
$status  = isset($_GET['status'])   ? trim($_GET['status']) : '';
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$gender  = isset($_GET['gender'])   ? trim($_GET['gender']) : '';
$page    = isset($_GET['page'])     ? (int)$_GET['page'] : 1;
$limit   = isset($_GET['limit'])    ? (int)$_GET['limit'] : 20;
if ($page < 1) $page = 1;
if ($limit < 1 || $limit > 200) $limit = 20;
$offset = ($page - 1) * $limit;

// ==============================================
// WHERE
// ==============================================
$where  = ["s.tenant_id = ?", "s.deleted_at IS NULL"];
$params = [$tenantId];

if ($search !== '') {
    $st = "%$search%";
    $where[] = "(s.first_name LIKE ?
                 OR s.middle_name LIKE ?
                 OR s.last_name LIKE ?
                 OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?
                 OR s.email LIKE ?
                 OR s.student_number LIKE ?)";
    for ($i = 0; $i < 6; $i++) $params[] = $st;
}
if ($status === 'active')   $where[] = "s.is_active = 1";
if ($status === 'inactive') $where[] = "s.is_active = 0";
if ($classId > 0) {
    $where[] = "s.class_id = ?";
    $params[] = $classId;
}
if ($gender !== '') {
    $where[] = "s.gender = ?";
    $params[] = $gender;
}
$whereClause = implode(' AND ', $where);

// ==============================================
// COUNT
// ==============================================
$countRow = $db->fetchOne("SELECT COUNT(*) AS total FROM students s WHERE $whereClause", $params);
$totalRecords = (int)($countRow['total'] ?? 0);
$totalPages   = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 0;

// ==============================================
// LIST
// ==============================================
$sql = "SELECT s.id, s.uuid, s.student_number, s.first_name, s.middle_name, s.last_name,
               s.email, s.primary_phone, s.gender, s.enrollment_date, s.enrollment_status,
               s.is_active, s.created_at, s.guardian_name, s.guardian_phone, s.guardian_relationship,
               c.id AS class_id, c.class_name, c.class_code
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE $whereClause
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?";

$listParams   = $params;
$listParams[] = $limit;
$listParams[] = $offset;
$studentList  = $db->fetchAll($sql, $listParams);

// ==============================================
// LOOKUPS
// ==============================================
$classes = $db->fetchAll(
    "SELECT id, class_name, class_code
     FROM classes
     WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL
     ORDER BY class_name",
    [$tenantId]
);

$statuses = [
    ['value' => 'active',   'label' => 'Active'],
    ['value' => 'inactive', 'label' => 'Inactive'],
];
$genders = [
    ['value' => 'Male',   'label' => 'Male'],
    ['value' => 'Female', 'label' => 'Female'],
    ['value' => 'Other',  'label' => 'Other'],
];

// ==============================================
// TENANT / SCHOOL NAMES
// ==============================================
$tenantName = '';
try {
    $t = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
    if ($t) $tenantName = $t['tenant_name'] ?? ('Tenant #' . $tenantId);
} catch (Exception $e) {
    $tenantName = 'Tenant #' . $tenantId;
}

$schoolName = '';
if ($schoolId > 0) {
    try {
        $s = $db->fetchOne("SELECT school_name FROM schools WHERE id = ? AND deleted_at IS NULL", [$schoolId]);
        if ($s) $schoolName = $s['school_name'] ?? '';
    } catch (Exception $e) {
        $schoolName = '';
    }
}

// ==============================================
// STATS
// ==============================================
$totalStudents    = (int)($db->getValue("SELECT COUNT(*) FROM students s WHERE s.tenant_id = ? AND s.deleted_at IS NULL", [$tenantId]) ?? 0);
$activeStudents   = (int)($db->getValue("SELECT COUNT(*) FROM students s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.is_active = 1", [$tenantId]) ?? 0);
$inactiveStudents = (int)($db->getValue("SELECT COUNT(*) FROM students s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.is_active = 0", [$tenantId]) ?? 0);
$maleStudents     = (int)($db->getValue("SELECT COUNT(*) FROM students s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.gender = 'Male'",   [$tenantId]) ?? 0);
$femaleStudents   = (int)($db->getValue("SELECT COUNT(*) FROM students s WHERE s.tenant_id = ? AND s.deleted_at IS NULL AND s.gender = 'Female'", [$tenantId]) ?? 0);
$newThisMonth     = (int)($db->getValue(
    "SELECT COUNT(*) FROM students s
     WHERE s.tenant_id = ? AND s.deleted_at IS NULL
       AND MONTH(s.created_at) = MONTH(CURRENT_DATE())
       AND YEAR(s.created_at)  = YEAR(CURRENT_DATE())",
    [$tenantId]
) ?? 0);
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

        .sidebar .nav-sub {
            padding-left: 24px;
        }

        .sidebar .nav-sub .nav-link {
            font-size: 13px;
            padding: 8px 14px;
            color: rgba(255, 255, 255, 0.55);
        }

        .sidebar .nav-sub .nav-link.active {
            background: rgba(79, 172, 254, 0.18);
            color: #fff;
            box-shadow: none;
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
        }

        .autocomplete-dropdown .ac-item:hover,
        .autocomplete-dropdown .ac-item.active {
            background: #f0f7ff;
        }

        .autocomplete-dropdown .ac-item.active {
            background: linear-gradient(135deg, #e3f0ff 0%, #e8f5ff 100%);
            box-shadow: inset 3px 0 0 #4facfe;
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

        .student-avatar-text {
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

        .student-name {
            font-weight: 600;
            color: #1a1a2e;
        }

        .student-number {
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

        .badge-gender {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-gender.male {
            background: #cce5ff;
            color: #004085;
        }

        .badge-gender.female {
            background: #fce4ec;
            color: #880e4f;
        }

        .badge-gender.other {
            background: #e9ecef;
            color: #495057;
        }

        .class-badge {
            display: inline-block;
            background: #f0f2f5;
            color: #495057;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
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

            .sidebar .nav-sub {
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

            .sidebar .nav-sub {
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
                        <h1><i class="fas fa-user-graduate me-2"></i>Student Management</h1>
                        <p>Manage all students across your institution</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/students/create.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i> Add Student
                        </a>
                    </div>
                </div>

                <div class="tenant-banner">
                    <div class="tenant-info">
                        <i class="fas fa-building"></i>
                        <div>
                            <div class="tenant-name"><?php echo htmlspecialchars($tenantName); ?></div>
                            <?php if ($schoolName !== ''): ?>
                                <div style="font-size:12px;color:#6c757d;margin-top:2px;">
                                    <i class="fas fa-school me-1"></i> <?php echo htmlspecialchars($schoolName); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="tenant-badge"><i class="fas fa-check-circle me-1"></i> Data Isolation Active</div>
                </div>

                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($totalStudents); ?></div>
                            <div class="stat-label">Total Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($activeStudents); ?></div>
                            <div class="stat-label">Active Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-user-slash"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($inactiveStudents); ?></div>
                            <div class="stat-label">Inactive Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon teal"><i class="fas fa-male"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($maleStudents); ?></div>
                            <div class="stat-label">Male Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon pink"><i class="fas fa-female"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($femaleStudents); ?></div>
                            <div class="stat-label">Female Students</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-user-plus"></i></div>
                        <div>
                            <div class="stat-number"><?php echo number_format($newThisMonth); ?></div>
                            <div class="stat-label">New This Month</div>
                        </div>
                    </div>
                </div>

                <div class="filters-bar">
                    <div class="filter-group" style="flex:1.5;">
                        <label><i class="fas fa-search me-1"></i> Search</label>
                        <div class="search-autocomplete-wrap" id="searchWrap">
                            <input type="text"
                                class="form-control"
                                id="searchInput"
                                placeholder="Start typing a student name..."
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
                            <div class="autocomplete-dropdown" id="autocompleteDropdown" role="listbox"></div>
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
                        <label>Class</label>
                        <select class="form-select" id="classFilter" onchange="applyFilters()">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo (int)$cls['id']; ?>" <?php echo $classId === (int)$cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['class_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Gender</label>
                        <select class="form-select" id="genderFilter" onchange="applyFilters()">
                            <option value="">All Genders</option>
                            <?php foreach ($genders as $g): ?>
                                <option value="<?php echo $g['value']; ?>" <?php echo $gender === $g['value'] ? 'selected' : ''; ?>><?php echo $g['label']; ?></option>
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

                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student #</th>
                                <th>Class</th>
                                <th>Gender</th>
                                <th>Guardian</th>
                                <th>Status</th>
                                <th style="text-align:center;min-width:220px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="studentTableBody">
                            <?php if (empty($studentList)): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="fas fa-user-graduate"></i>
                                        <h5>No Students Found</h5>
                                        <p>No students match your search criteria. Try adjusting your filters or <a href="/platform/tenant/students/create.php">add a new student</a>.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($studentList as $student): ?>
                                    <?php
                                    $first = $student['first_name'] ?? '';
                                    $mid   = $student['middle_name'] ?? '';
                                    $last  = $student['last_name'] ?? '';
                                    $full  = trim(preg_replace('/\s+/', ' ', $first . ' ' . $mid . ' ' . $last));
                                    $display = $full !== '' ? $full : ('Student #' . $student['id']);
                                    $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
                                    if ($initials === '') $initials = 'ST';
                                    $isActive = ((int)($student['is_active'] ?? 0)) === 1;
                                    $statusClass = $isActive ? 'active' : 'inactive';
                                    $statusLabel = $isActive ? 'Active' : 'Inactive';
                                    $genderVal = $student['gender'] ?? '';
                                    $genderClass = 'other';
                                    if ($genderVal === 'Male') $genderClass = 'male';
                                    elseif ($genderVal === 'Female') $genderClass = 'female';
                                    $gName  = $student['guardian_name']  ?? '';
                                    $gPhone = $student['guardian_phone'] ?? '';
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:10px;">
                                                <div class="student-avatar-text"><?php echo $initials; ?></div>
                                                <div>
                                                    <div class="student-name"><?php echo htmlspecialchars($display); ?></div>
                                                    <div class="student-number">
                                                        <i class="fas fa-envelope me-1"></i>
                                                        <?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;font-size:13px;">
                                                <?php echo htmlspecialchars($student['student_number'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if (!empty($student['enrollment_date'])): ?>
                                                <div style="font-size:11px;color:#6c757d;">
                                                    Enrolled: <?php echo date('M d, Y', strtotime($student['enrollment_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($student['class_name'])): ?>
                                                <span class="class-badge">
                                                    <i class="fas fa-book me-1"></i><?php echo htmlspecialchars($student['class_name']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-gender <?php echo $genderClass; ?>">
                                                <?php echo htmlspecialchars($genderVal !== '' ? $genderVal : 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($gName !== '' || $gPhone !== ''): ?>
                                                <?php if ($gName !== ''): ?>
                                                    <div style="font-size:12px;font-weight:600;"><?php echo htmlspecialchars($gName); ?></div>
                                                <?php endif; ?>
                                                <?php if ($gPhone !== ''): ?>
                                                    <div style="font-size:11px;color:#6c757d;">
                                                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($gPhone); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <a href="/platform/tenant/students/record.php?student_id=<?php echo (int)$student['id']; ?>" class="btn btn-sm btn-outline-primary" title="Full Record">
                                                    <i class="fas fa-id-card"></i>
                                                </a>
                                                <a href="/platform/tenant/students/view.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-sm btn-outline-info" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="/platform/tenant/students/edit.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($isActive): ?>
                                                    <button class="btn btn-sm btn-outline-warning" onclick="toggleStatus(<?php echo (int)$student['id']; ?>, 0)" title="Deactivate">
                                                        <i class="fas fa-pause"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-outline-success" onclick="toggleStatus(<?php echo (int)$student['id']; ?>, 1)" title="Activate">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteStudent(<?php echo (int)$student['id']; ?>)" title="Delete">
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
                        <div class="info">
                            Showing <?php echo min($offset + 1, $totalRecords); ?>
                            to <?php echo min($offset + $limit, $totalRecords); ?>
                            of <?php echo number_format($totalRecords); ?> students
                        </div>
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
                                $endPage   = min($totalPages, $page + 3);
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
    <script src="/assets/js/notifications-bell.js" defer></script>
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
            if (window.innerWidth > 768) document.getElementById('sidebar').classList.remove('open');
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) window.location.href = '/platform/logout.php';
        }

        function getFilters() {
            return {
                search: document.getElementById('searchInput').value.trim(),
                status: document.getElementById('statusFilter').value,
                class_id: document.getElementById('classFilter').value,
                gender: document.getElementById('genderFilter').value
            };
        }

        function applyFilters() {
            const f = getFilters();
            let url = window.location.pathname + '?';
            if (f.search) url += 'search=' + encodeURIComponent(f.search) + '&';
            if (f.status) url += 'status=' + encodeURIComponent(f.status) + '&';
            if (f.class_id && f.class_id !== '0') url += 'class_id=' + encodeURIComponent(f.class_id) + '&';
            if (f.gender) url += 'gender=' + encodeURIComponent(f.gender) + '&';
            url += 'page=1';
            window.location.href = url;
        }

        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('classFilter').value = '0';
            document.getElementById('genderFilter').value = '';
            applyFilters();
        }

        function changePage(page) {
            const f = getFilters();
            let url = window.location.pathname + '?page=' + page;
            if (f.search) url += '&search=' + encodeURIComponent(f.search);
            if (f.status) url += '&status=' + encodeURIComponent(f.status);
            if (f.class_id && f.class_id !== '0') url += '&class_id=' + encodeURIComponent(f.class_id);
            if (f.gender) url += '&gender=' + encodeURIComponent(f.gender);
            window.location.href = url;
        }

        // Autocomplete
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

            function escapeHtml(s) {
                if (s === null || s === undefined) return '';
                return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function escapeRegex(s) {
                return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function highlight(text, q) {
                const safe = escapeHtml(text || '');
                if (!q) return safe;
                try {
                    const re = new RegExp('(' + escapeRegex(q) + ')', 'ig');
                    return safe.replace(re, '<mark>$1</mark>');
                } catch (e) {
                    return safe;
                }
            }

            function updateClear() {
                if (searchInput.value.trim() !== '') searchWrap.classList.add('has-value');
                else searchWrap.classList.remove('has-value');
            }

            function hide() {
                dropdown.classList.remove('show');
                activeIndex = -1;
            }

            function show() {
                dropdown.classList.add('show');
            }

            function setActive(i) {
                const items = dropdown.querySelectorAll('.ac-item');
                items.forEach(el => el.classList.remove('active'));
                activeIndex = i;
                if (i >= 0 && items[i]) {
                    items[i].classList.add('active');
                    items[i].scrollIntoView({
                        block: 'nearest'
                    });
                }
            }

            function render(results, q) {
                currentResults = results;
                activeIndex = -1;
                if (!results.length) {
                    dropdown.innerHTML = `<div class="ac-empty"><i class="fas fa-user-slash"></i>No students found matching "<strong>${escapeHtml(q)}</strong>"</div>`;
                    show();
                    return;
                }
                let html = '<div class="ac-header">Student Suggestions</div>';
                results.forEach((r, i) => {
                    const meta = [];
                    if (r.student_number) meta.push(`<i class="fas fa-id-badge"></i>${escapeHtml(r.student_number)}`);
                    if (r.class_name) meta.push(`<i class="fas fa-book"></i>${escapeHtml(r.class_name)}`);
                    html += `
                        <a href="/platform/tenant/students/view.php?id=${r.id}"
                           class="ac-item" data-index="${i}">
                            <div class="ac-avatar-text">${escapeHtml(r.initials)}</div>
                            <div class="ac-body">
                                <div class="ac-name">${highlight(r.name, q)}</div>
                                <div class="ac-meta">${meta.join(' &nbsp;·&nbsp; ')}</div>
                            </div>
                        </a>`;
                });
                html += `<div class="ac-footer"><span><kbd>↑</kbd> <kbd>↓</kbd> to navigate · <kbd>Enter</kbd> to open · <kbd>Esc</kbd> to close</span></div>`;
                dropdown.innerHTML = html;
                show();
            }

            function fetchResults(q) {
                const id = ++currentRequestId;
                searchWrap.classList.add('loading');
                fetch('/platform/tenant/students/index.php?ajax_search_students=1&q=' + encodeURIComponent(q), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (id !== currentRequestId) return;
                        searchWrap.classList.remove('loading');
                        render((data && data.results) ? data.results : [], q);
                    })
                    .catch(() => {
                        if (id !== currentRequestId) return;
                        searchWrap.classList.remove('loading');
                        hide();
                    });
            }

            function schedule() {
                const q = searchInput.value.trim();
                updateClear();
                if (debounceTimer) clearTimeout(debounceTimer);
                if (q.length < 1) {
                    hide();
                    return;
                }
                debounceTimer = setTimeout(() => fetchResults(q), 250);
            }

            searchInput.addEventListener('input', schedule);
            searchInput.addEventListener('focus', function() {
                updateClear();
                if (searchInput.value.trim().length >= 1 && currentResults.length) show();
            });
            searchInput.addEventListener('keydown', function(e) {
                const items = dropdown.querySelectorAll('.ac-item');
                const open = dropdown.classList.contains('show');
                if (e.key === 'ArrowDown') {
                    if (!open && searchInput.value.trim().length >= 1) {
                        schedule();
                        e.preventDefault();
                        return;
                    }
                    if (!items.length) return;
                    e.preventDefault();
                    setActive((activeIndex + 1) % items.length);
                } else if (e.key === 'ArrowUp') {
                    if (!open || !items.length) return;
                    e.preventDefault();
                    setActive((activeIndex - 1 + items.length) % items.length);
                } else if (e.key === 'Enter') {
                    if (open && activeIndex >= 0 && items[activeIndex]) {
                        e.preventDefault();
                        window.location.href = items[activeIndex].getAttribute('href');
                        return;
                    }
                    e.preventDefault();
                    hide();
                    applyFilters();
                } else if (e.key === 'Escape') {
                    hide();
                    searchInput.blur();
                }
            });
            dropdown.addEventListener('click', function(e) {
                const item = e.target.closest('.ac-item');
                if (item && item.getAttribute('href')) window.location.href = item.getAttribute('href');
            });
            dropdown.addEventListener('mousedown', e => e.preventDefault());
            if (clearBtn) clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                updateClear();
                hide();
                currentResults = [];
                searchInput.focus();
            });
            document.addEventListener('click', e => {
                if (!searchWrap.contains(e.target)) hide();
            });
            updateClear();
        })();

        function toggleStatus(id, status) {
            const action = status == 1 ? 'activate' : 'deactivate';
            if (!confirm('Are you sure you want to ' + action + ' this student?')) return;
            const btn = event.target.closest('button');
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            fetch('/api/platform/students/update_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id,
                        is_active: status
                    })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else {
                        alert('Error: ' + (res.message || 'Failed'));
                        btn.disabled = false;
                        btn.innerHTML = original;
                    }
                })
                .catch(err => {
                    alert('Error: ' + err.message);
                    btn.disabled = false;
                    btn.innerHTML = original;
                });
        }

        function deleteStudent(id) {
            if (!confirm('Are you sure you want to delete this student? This action cannot be undone.')) return;
            if (!confirm('Really? This will permanently delete all associated data.')) return;
            const btn = event.target.closest('button');
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            fetch('/api/platform/students/delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: id
                    })
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) location.reload();
                    else {
                        alert('Error: ' + (res.message || 'Failed'));
                        btn.disabled = false;
                        btn.innerHTML = original;
                    }
                })
                .catch(err => {
                    alert('Error: ' + err.message);
                    btn.disabled = false;
                    btn.innerHTML = original;
                });
        }

        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {}
            }
        }
        document.addEventListener('DOMContentLoaded', loadUserInfo);
    </script>
</body>

</html>